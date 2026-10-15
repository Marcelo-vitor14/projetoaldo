from fastapi.testclient import TestClient

from src.api.main import app


client = TestClient(app)


def test_liveness_retorna_ok():
    response = client.get("/health/liveness")

    assert response.status_code == 200
    assert response.json() == {"status": "ok"}
    assert response.headers["x-content-type-options"] == "nosniff"
    assert response.headers["x-frame-options"] == "DENY"
    assert response.headers["x-request-id"]


def test_pagina_web_e_servida_na_raiz():
    response = client.get("/")

    assert response.status_code == 200
    assert "text/html" in response.headers["content-type"]
    assert "Tira-Dúvidas" in response.text


def test_cors_so_permite_origem_configurada():
    permitted = client.get("/health/liveness", headers={"Origin": "http://localhost:8501"})
    blocked = client.get("/health/liveness", headers={"Origin": "https://example.invalid"})

    assert permitted.headers.get("access-control-allow-origin") == "http://localhost:8501"
    assert blocked.headers.get("access-control-allow-origin") is None


def test_readiness_informa_base_nao_configurada():
    response = client.get("/health/readiness")

    assert response.status_code == 503
    assert response.json()["vector_store"] == "not_configured"


def test_calculo_api_retorna_resultado_estruturado():
    response = client.post("/api/v1/calcular-nota", json={"p1": 5.5, "p2": 6})

    assert response.status_code == 200
    assert response.json() == {
        "media_parcial": 5.75,
        "status": "prova_final",
        "nota_necessaria_final": 6.25,
    }


def test_calculo_api_rejeita_nota_invalida_sem_repetir_valor():
    response = client.post("/api/v1/calcular-nota", json={"p1": 15, "p2": 6})

    assert response.status_code == 422
    assert response.json()["detail"] == "Requisição inválida."
    assert "15" not in response.text


def test_chat_calcula_notas_informadas_em_linguagem_natural():
    response = client.post(
        "/api/v1/chat",
        json={
            "session_id": "sessao-teste",
            "messages": [{"role": "user", "content": "Tirei 5,5 na P1 e 6,0 na P2."}],
        },
    )

    assert response.status_code == 200
    assert "5.75" in response.json()["response"]
    assert "6.25" in response.json()["response"]
    assert response.json()["tool_called"] == "calcular_nota_final"
    assert response.json()["calculo"]["nota_necessaria_final"] == 6.25
    assert response.json()["calculo"]["p1"] == 5.5


def test_chat_nao_finge_ter_consultado_documentos():
    response = client.post(
        "/api/v1/chat",
        json={
            "session_id": "sessao-teste",
            "messages": [{"role": "user", "content": "Qual é o prazo para trancar matrícula?"}],
        },
    )

    assert response.status_code == 200
    assert response.json()["sources"] == []
    assert "não encontrei" in response.json()["response"].lower()


def test_chat_exige_ultima_mensagem_do_usuario():
    response = client.post(
        "/api/v1/chat",
        json={
            "session_id": "sessao-teste",
            "messages": [{"role": "assistant", "content": "Resposta anterior."}],
        },
    )

    assert response.status_code == 422
    assert response.json()["detail"] == "Requisição inválida."


def test_chat_rejeita_historico_acima_do_limite():
    response = client.post(
        "/api/v1/chat",
        json={
            "session_id": "sessao-teste",
            "messages": [{"role": "user", "content": "x" * 8000}] * 3,
        },
    )

    assert response.status_code == 422
