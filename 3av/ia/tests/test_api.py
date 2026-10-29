from fastapi.testclient import TestClient

from src.api.main import app


client = TestClient(app, headers={"X-IA-Token": "token-de-teste"})


def test_liveness_retorna_ok():
    response = client.get("/health/liveness")

    assert response.status_code == 200
    assert response.json() == {"status": "ok"}
    assert response.headers["x-content-type-options"] == "nosniff"
    assert response.headers["x-frame-options"] == "DENY"
    assert response.headers["x-request-id"]


def test_tela_antiga_saiu_a_interface_agora_e_o_portal():
    assert client.get("/").status_code == 404


def test_api_sem_token_ou_com_token_errado_e_recusada():
    sem_token = TestClient(app)
    corpo = {"notas": [7, 8]}

    assert sem_token.post("/api/v1/calcular-nota", json=corpo).status_code == 401
    assert sem_token.post("/api/v1/calcular-nota", json=corpo, headers={"X-IA-Token": "errado"}).status_code == 401
    assert client.post("/api/v1/calcular-nota", json=corpo).status_code == 200


def test_saude_continua_aberta_sem_token():
    assert TestClient(app).get("/health/liveness").status_code == 200


def test_sem_token_configurado_a_api_recusa_tudo(monkeypatch):
    from src.config import get_settings

    monkeypatch.setattr(get_settings(), "ia_token", "")
    assert client.post("/api/v1/calcular-nota", json={"notas": [7, 8]}).status_code == 503


def test_readiness_informa_base_nao_configurada():
    response = client.get("/health/readiness")

    assert response.status_code == 503
    assert response.json()["vector_store"] == "not_configured"


def test_calculo_api_retorna_resultado_estruturado():
    response = client.post("/api/v1/calcular-nota", json={"notas": [5.5, 6]})

    assert response.status_code == 200
    assert response.json() == {
        "media_parcial": 5.75,
        "status": "prova_final",
        "nota_necessaria_final": 6.25,
    }


def test_calculo_api_rejeita_nota_invalida_sem_repetir_valor():
    response = client.post("/api/v1/calcular-nota", json={"notas": [15, 6]})

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
    assert response.json()["calculo"]["notas"] == [5.5, 6.0]


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


def _chat(texto: str):
    return client.post("/api/v1/chat", json={"session_id": "s", "messages": [{"role": "user", "content": texto}]})


def test_calculo_api_rejeita_campo_extra():
    response = client.post("/api/v1/calcular-nota", json={"notas": [5, 6], "p3": 7})

    assert response.status_code == 422


def test_calculo_api_aprovado_direto_nao_tem_nota_da_final():
    response = client.post("/api/v1/calcular-nota", json={"notas": [8, 9]})

    assert response.json() == {"media_parcial": 8.5, "status": "aprovado_direto", "nota_necessaria_final": None}


def test_chat_sem_ia_entende_formato_com_dois_pontos():
    response = _chat("P1: 8 P2: 9")

    assert response.json()["calculo"]["status"] == "aprovado_direto"
    assert "aprovação direta" in response.json()["response"]


def test_chat_sem_ia_recusa_nota_acima_de_dez():
    response = _chat("P1 12 e P2 5")

    assert response.status_code == 200
    assert "entre 0 e 10" in response.json()["response"]
    assert response.json()["calculo"] is None


def test_chat_rejeita_papel_de_mensagem_desconhecido():
    response = client.post("/api/v1/chat", json={"session_id": "s", "messages": [{"role": "system", "content": "oi"}]})

    assert response.status_code == 422


def test_chat_aceita_historico_com_varias_mensagens():
    response = client.post(
        "/api/v1/chat",
        json={
            "session_id": "s",
            "messages": [
                {"role": "user", "content": "Olá"},
                {"role": "assistant", "content": "Olá! Como posso ajudar?"},
                {"role": "user", "content": "P1 5 e P2 5"},
            ],
        },
    )

    assert response.status_code == 200
    assert response.json()["calculo"]["nota_necessaria_final"] == 7.0


def test_chat_devolve_503_amigavel_quando_o_gemini_cai(monkeypatch):
    import src.api.main as main
    from src.agent.core import AgenteIndisponivel

    def falha(*_args):
        raise AgenteIndisponivel("teste")

    monkeypatch.setattr(main, "get_settings", lambda: type("S", (), {"gemini_api_key": "chave-falsa", "ia_token": "token-de-teste"})())
    monkeypatch.setattr(main, "responder", falha)

    response = _chat("Quantas faltas posso ter?")

    assert response.status_code == 503
    assert "temporariamente indisponível" in response.json()["detail"]
