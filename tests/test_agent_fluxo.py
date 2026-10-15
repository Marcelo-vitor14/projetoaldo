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
        self.models = self

    def generate_content(self, **_kwargs):
        self.chamadas += 1
        proximo = self.roteiro.pop(0)
        if isinstance(proximo, Exception):
            raise proximo
        return proximo


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
    gemini(_chamada("calcular_nota_final", p1=5.5, p2=6), _texto("Você precisa de 6,25 na final."))

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
    cliente = gemini(*[_chamada("calcular_nota_final", p1=5, p2=5) for _ in range(core.MAX_RODADAS)])

    with pytest.raises(AgenteIndisponivel):
        responder(PERGUNTA)
    assert cliente.chamadas == core.MAX_RODADAS
