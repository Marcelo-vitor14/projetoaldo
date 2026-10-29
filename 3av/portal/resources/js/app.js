/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

/**
 * Efeito de digitação do chat. A resposta chega de verdade aos poucos (wire:stream), mas o Gemini manda
 * pedaços grandes; aqui a tela mostra o que já chegou letra por letra, num ritmo constante, e acelera se
 * ficar para trás (nunca mais de ~1 s atrás do que chegou). O texto é sempre o real.
 */
const ritmo = (falta) => Math.max(1.2, falta / 45); // letras por quadro: ~70 por segundo, ou alcança em ~0,75 s
const semMovimento = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function rolarSeNoFim() {
    const el = document.getElementById('mensagens');
    if (el && el.scrollHeight - el.scrollTop - el.clientHeight < 160) el.scrollTop = el.scrollHeight;
}

window.digitar = () => ({
    comecou: false,
    pronto: false,

    // Enquanto chega: copia o texto do span escondido (wire:stream) para o visível, aos poucos
    espelhar(fonte, saida) {
        let mostrados = 0;
        window.unisearchDigitados = 0;
        const passo = () => {
            if (!this.$el.isConnected) return; // a resposta terminou e virou mensagem
            const texto = fonte.textContent.replace(/\*\*/g, '');
            if (mostrados < texto.length) {
                mostrados = semMovimento() ? texto.length : Math.min(texto.length, mostrados + ritmo(texto.length - mostrados));
                saida.textContent = texto.slice(0, Math.floor(mostrados));
                window.unisearchDigitados = Math.floor(mostrados);
                this.comecou = true;
                rolarSeNoFim();
            }
            requestAnimationFrame(passo);
        };
        requestAnimationFrame(passo);
    },

    // Quando termina: a mensagem final (já formatada) continua de onde a digitação parou
    revelar(el) {
        const nos = [];
        const andar = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        while (andar.nextNode()) nos.push({ no: andar.currentNode, texto: andar.currentNode.data });
        const total = nos.reduce((s, n) => s + n.texto.length, 0);
        let mostrados = semMovimento() ? total : Math.min(window.unisearchDigitados ?? 0, total);
        window.unisearchDigitados = undefined;
        const desenhar = () => {
            let resta = Math.floor(mostrados);
            for (const n of nos) {
                n.no.data = n.texto.slice(0, Math.max(0, resta));
                resta -= n.texto.length;
            }
        };
        const passo = () => {
            mostrados = Math.min(total, mostrados + ritmo(total - mostrados));
            desenhar();
            rolarSeNoFim();
            if (mostrados < total) requestAnimationFrame(passo);
            else this.pronto = true;
        };
        desenhar();
        if (mostrados >= total) this.pronto = true;
        else requestAnimationFrame(passo);
    },
});
