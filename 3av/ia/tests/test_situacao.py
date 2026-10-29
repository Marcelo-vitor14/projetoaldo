import pytest
from fastapi.testclient import TestClient

from src.api.main import app
from src.tools.situacao import calcular_situacao

client = TestClient(app, headers={"X-IA-Token": "token-de-teste"})


@pytest.mark.parametrize(
    ("p1", "p2", "pf", "faltas", "situacao", "media_final"),
    [
        (8.0, 7.0, None, 4, "aprovado_direto", None),
        (5.5, 6.0, None, 4, "prova_final", None),
        (5.5, 6.0, 7.0, 4, "aprovado_final", 6.375),
        (5.0, 5.0, 6.0, 4, "reprovado_final", 5.5),
        (3.0, 4.0, None, 4, "reprovado_nota", None),
        (9.0, 9.0, None, 16, "reprovado_falta", None),
        (7.0, None, None, 2, "cursando", None),
    ],
)
def test_situacao_segue_o_regimento(p1, p2, pf, faltas, situacao, media_final):
    notas = [n for n in (p1, p2) if n is not None]
    r = calcular_situacao(60, faltas, notas, 2, pf)

    assert r.situacao == situacao
    assert r.media_final == media_final


def test_limite_de_faltas_e_25_por_cento_e_o_limite_exato_ainda_passa():
    r = calcular_situacao(60, 15, [8.0, 8.0])

    assert r.limite_faltas == 15
    assert r.faltas_restantes == 0
    assert r.situacao == "aprovado_direto"


def test_falta_reprova_mesmo_com_prova_final_aprovada():
    assert calcular_situacao(80, 21, [5.0, 5.0], 2, 9.0).situacao == "reprovado_falta"


def test_prova_final_mostra_nota_necessaria():
    r = calcular_situacao(60, 0, [5.5, 6.0])

    assert r.media_parcial == 5.75
    assert r.nota_necessaria_final == 6.25


def test_api_calcula_varias_disciplinas_de_uma_vez():
    response = client.post(
        "/api/v1/situacao-academica",
        json={"disciplinas": [
            {"codigo": "BD2", "carga_horaria": 60, "faltas": 4, "notas": [5.5, 6.0]},
            {"codigo": "RED", "carga_horaria": 80, "faltas": 21, "notas": [9.0, 9.0]},
        ]},
    )

    assert response.status_code == 200
    d = response.json()["disciplinas"]
    assert [x["codigo"] for x in d] == ["BD2", "RED"]
    assert d[0]["situacao"] == "prova_final" and d[0]["nota_necessaria_final"] == 6.25
    assert d[1]["situacao"] == "reprovado_falta" and d[1]["limite_faltas"] == 20


def test_api_rejeita_lista_vazia_e_nota_invalida():
    assert client.post("/api/v1/situacao-academica", json={"disciplinas": []}).status_code == 422
    ruim = {"disciplinas": [{"codigo": "X", "carga_horaria": 60, "faltas": 0, "notas": [11]}]}
    assert client.post("/api/v1/situacao-academica", json=ruim).status_code == 422


def test_com_tres_avaliacoes_so_fecha_quando_todas_saem():
    assert calcular_situacao(60, 2, [8.0, 9.0], 3).situacao == "cursando"
    r = calcular_situacao(60, 2, [8.0, 9.0, 4.0], 3)
    assert r.situacao == "aprovado_direto"
    assert r.media_parcial == 7.0


def test_api_recusa_mais_notas_do_que_avaliacoes():
    ruim = {"disciplinas": [{"codigo": "X", "carga_horaria": 60, "faltas": 0, "total_avaliacoes": 2, "notas": [7, 8, 9]}]}
    assert client.post("/api/v1/situacao-academica", json=ruim).status_code == 422
