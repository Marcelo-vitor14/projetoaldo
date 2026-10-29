"""Resposta do chat aos poucos (streaming): agente e rota /api/v1/chat/aos-poucos."""

import json

from fastapi.testclient import TestClient
from google.genai import errors

import src.api.main as main
from src.agent.core import responder_aos_poucos
from src.api.main import app
from tests.test_agent_fluxo import _chamada, _texto, gemini  # noqa: F401 (fixture)
from tests.test_dados_aluno import ALUNO

client = TestClient(app, headers={"X-IA-Token": "token-de-teste"})


def test_resposta_vem_em_pedacos_e_termina_com_o_resultado(gemini):  # noqa: F811
    gemini(_texto("Você pode ter até 15 horas de faltas."))

    eventos = list(responder_aos_poucos([{"role": "user", "content": "quantas faltas?"}]))

    pedacos = [e["texto"] for e in eventos if e["tipo"] == "texto"]
    assert len(pedacos) > 1 and "".join(pedacos) == "Você pode ter até 15 horas de faltas."
    assert eventos[-1]["tipo"] == "fim" and eventos[-1]["resposta"].texto == "Você pode ter até 15 horas de faltas."


def test_ferramentas_rodam_antes_e_o_cartao_vem_no_fim(gemini):  # noqa: F811
    gemini(_chamada("consultar_situacao_aluno", disciplina="SAD"), _texto("Prova final (Art. 23)."))

    eventos = list(responder_aos_poucos([{"role": "user", "content": "eu passei em SAD?"}], ALUNO))

    assert all("consultar" not in e.get("texto", "") for e in eventos)  # a chamada de ferramenta não vira texto
    fim = eventos[-1]["resposta"]
    assert fim.ferramenta == "consultar_situacao_aluno" and fim.dados_aluno[0]["codigo"] == "SAD"


def test_rota_manda_uma_linha_json_por_pedaco(monkeypatch, gemini):  # noqa: F811
    monkeypatch.setattr(main, "get_settings", lambda: type("S", (), {"gemini_api_key": "x", "ia_token": "token-de-teste"})())
    gemini(_texto("Até 15 horas (Art. 15, p. 2)."))

    r = client.post("/api/v1/chat/aos-poucos", json={"session_id": "s", "messages": [{"role": "user", "content": "faltas?"}]})

    linhas = [json.loads(l) for l in r.text.splitlines()]
    assert r.headers["content-type"].startswith("application/x-ndjson")
    assert [l["tipo"] for l in linhas][-1] == "fim" and linhas.count({"tipo": "texto", "texto": "Até "}) == 1
    assert linhas[-1]["response"] == "Até 15 horas (Art. 15, p. 2)."


def test_gemini_fora_do_ar_vira_linha_de_erro(monkeypatch, gemini):  # noqa: F811
    monkeypatch.setattr(main, "get_settings", lambda: type("S", (), {"gemini_api_key": "x", "ia_token": "token-de-teste"})())
    erro = errors.APIError(503, {"error": {"code": 503, "message": "ocupado", "status": "UNAVAILABLE"}})
    gemini(erro, erro, erro)

    r = client.post("/api/v1/chat/aos-poucos", json={"session_id": "s", "messages": [{"role": "user", "content": "oi"}]})

    assert [json.loads(l)["tipo"] for l in r.text.splitlines()] == ["erro"]


def test_rota_aos_poucos_exige_token():
    assert TestClient(app).post("/api/v1/chat/aos-poucos", json={"session_id": "s", "messages": [{"role": "user", "content": "oi"}]}).status_code == 401
