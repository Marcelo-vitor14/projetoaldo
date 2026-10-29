"""Fluxo do agente com um Gemini falso: roteiro de respostas, sem internet."""

from types import SimpleNamespace

import pytest
from google.genai import errors

import src.agent.core as core
from src.agent.core import AgenteIndisponivel, RespostaAgente, _executar, responder


def _chamada(nome: str, **args):
    """Resposta do modelo pedindo para executar uma ferramenta."""
    return SimpleNamespace(
        function_calls=[SimpleNamespace(name=nome, args=args)],
        text=None,
        candidates=[SimpleNamespace(content=f"chamada {nome}")],
    )


def _texto(texto: str):
    """Resposta final do modelo, em texto."""
    return SimpleNamespace(function_calls=None, text=texto, candidates=[])


class GeminiFalso:
    def __init__(self, roteiro):
        self.roteiro = list(roteiro)
        self.chamadas = 0
        self.modelos = []  # modelo usado em cada chamada
        self.models = self

    def generate_content(self, **kwargs):
        self.chamadas += 1
        self.modelos.append(kwargs.get("model"))
        proximo = self.roteiro.pop(0)
        if isinstance(proximo, Exception):
            raise proximo
        return proximo

    def generate_content_stream(self, **_kwargs):
        """Streaming: um texto vira pedaços de uma palavra; uma chamada de ferramenta vem num pedaço só."""
        resposta = self.generate_content(**_kwargs)
        if resposta.function_calls:
            return iter([resposta])
        palavras = resposta.text.split(" ")
        return iter(_texto(p + (" " if i < len(palavras) - 1 else "")) for i, p in enumerate(palavras))


@pytest.fixture
def gemini(monkeypatch):
    """Instala o Gemini falso no agente e devolve uma função para definir o roteiro."""
    monkeypatch.setattr(core, "get_settings", lambda: SimpleNamespace(gemini_api_key="chave-falsa", gemini_model="falso"))
    estado = {}

    def instalar(*roteiro):
        estado["cliente"] = GeminiFalso(roteiro)
        monkeypatch.setattr(core.genai, "Client", lambda **_kwargs: estado["cliente"])
        return estado["cliente"]

    return instalar


PERGUNTA = [{"role": "user", "content": "minha P1 deu cinco e meio e a P2 seis"}]


def test_agente_chama_a_calculadora_e_devolve_o_resultado(gemini):
    gemini(_chamada("calcular_nota_final", notas=[5.5, 6]), _texto("Você precisa de 6,25 na final."))

    resposta = responder(PERGUNTA)

    assert resposta.texto == "Você precisa de 6,25 na final."
    assert resposta.ferramenta == "calcular_nota_final"
    assert resposta.calculo["nota_necessaria_final"] == 6.25


def test_agente_so_devolve_as_fontes_que_a_resposta_cita(gemini, monkeypatch):
    trechos = [
        {"documento": "R.pdf", "artigo": "Art. 15", "pagina": 2, "trecho": "Limite de faltas.", "distancia": 0.2},
        {"documento": "R.pdf", "artigo": "Art. 14", "pagina": 2, "trecho": "Frequência obrigatória.", "distancia": 0.3},
    ]
    monkeypatch.setattr(core, "buscar", lambda _consulta: trechos)
    gemini(_chamada("buscar_regimento", consulta="faltas"), _texto("O limite é de 15 horas (Art. 15, p. 2)."))

    resposta = responder([{"role": "user", "content": "Quantas faltas posso ter?"}])

    assert [f["artigo"] for f in resposta.fontes] == ["Art. 15"]
    assert resposta.ferramenta is None


def test_busca_sem_trechos_avisa_o_modelo(monkeypatch):
    monkeypatch.setattr(core, "buscar", lambda _consulta: [])

    resultado = _executar("buscar_regimento", {"consulta": "mensalidade"}, RespostaAgente(texto=""))

    assert resultado["trechos"] == []
    assert "Nenhum trecho" in resultado["aviso"]


def test_ferramenta_desconhecida_devolve_erro():
    resultado = _executar("apagar_banco", {}, RespostaAgente(texto=""))

    assert "desconhecida" in resultado["erro"]


def test_sem_chave_o_agente_fica_indisponivel(monkeypatch):
    monkeypatch.setattr(core, "get_settings", lambda: SimpleNamespace(gemini_api_key="", gemini_model="falso"))

    with pytest.raises(AgenteIndisponivel):
        responder(PERGUNTA)


def test_erro_do_google_vira_agente_indisponivel(gemini):
    cliente = gemini(errors.ClientError(400, {"error": {"code": 400, "message": "inválido", "status": "INVALID_ARGUMENT"}}))

    with pytest.raises(AgenteIndisponivel):
        responder(PERGUNTA)
    assert cliente.chamadas == 1  # erro 400 não é temporário: não repete


def test_agente_desiste_se_o_modelo_ficar_em_laco(gemini):
    cliente = gemini(*[_chamada("calcular_nota_final", notas=[5, 5]) for _ in range(core.MAX_RODADAS)])

    with pytest.raises(AgenteIndisponivel):
        responder(PERGUNTA)
    assert cliente.chamadas == core.MAX_RODADAS


def _limite():
    return errors.APIError(429, {"error": {"code": 429, "message": "quota", "status": "RESOURCE_EXHAUSTED"}})


@pytest.mark.parametrize("aos_poucos", [False, True])
def test_limite_por_minuto_passa_para_o_reserva_e_fica_nele_por_um_minuto(monkeypatch, aos_poucos):
    monkeypatch.setattr(core, "get_settings", lambda: SimpleNamespace(gemini_api_key="k", gemini_model="principal", gemini_model_reserva="reserva"))
    monkeypatch.setattr(core, "_reserva_ate", 0.0)
    agora = [1000.0]
    monkeypatch.setattr(core.time, "monotonic", lambda: agora[0])
    cliente = GeminiFalso([_limite(), _texto("Resposta do reserva."), _texto("Ainda no reserva."), _texto("De volta.")])
    monkeypatch.setattr(core.genai, "Client", lambda **_kwargs: cliente)

    def perguntar():
        msgs = [{"role": "user", "content": "oi"}]
        if aos_poucos:
            return [e for e in core.responder_aos_poucos(msgs) if e["tipo"] == "fim"][0]["resposta"].texto
        return responder(msgs).texto

    assert perguntar() == "Resposta do reserva."
    agora[0] += 30
    assert perguntar() == "Ainda no reserva."  # não gasta pedido batendo no limite
    agora[0] += 31
    assert perguntar() == "De volta."
    assert cliente.modelos == ["principal", "reserva", "reserva", "principal"]


def test_sem_reserva_o_limite_continua_dando_indisponivel(monkeypatch):
    monkeypatch.setattr(core, "get_settings", lambda: SimpleNamespace(gemini_api_key="k", gemini_model="principal", gemini_model_reserva=""))
    monkeypatch.setattr(core, "_reserva_ate", 0.0)
    monkeypatch.setattr(core._gerar.retry, "sleep", lambda _s: None)
    monkeypatch.setattr(core.genai, "Client", lambda **_kwargs: GeminiFalso([_limite()] * 3))

    with pytest.raises(AgenteIndisponivel, match="429"):
        responder([{"role": "user", "content": "oi"}])
