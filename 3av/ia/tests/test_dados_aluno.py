"""Perguntas sobre a própria situação: a ferramenta, o agente (Gemini simulado) e a API."""

from fastapi.testclient import TestClient

import src.api.main as main
from src.api.main import app
from src.tools.dados_aluno import ResumoAluno, consultar_situacao_aluno
from tests.test_agent_fluxo import _chamada, _texto, gemini  # noqa: F401 (fixture)
from src.agent.core import responder

client = TestClient(app, headers={"X-IA-Token": "token-de-teste"})

RESUMO = {
    "curso": "Sistemas de Informação",
    "periodo_atual": 8,
    "semestre_atual": "2026.2",
    "disciplinas": [
        {"codigo": "SAD", "nome": "Sistemas de Apoio à Decisão", "semestre": "2026.2", "carga_horaria": 60,
         "total_avaliacoes": 2, "notas": [5.5, 6.0], "faltas": 4},
        {"codigo": "TC2", "nome": "Trabalho de Conclusão de Curso II", "semestre": "2026.2", "carga_horaria": 80,
         "total_avaliacoes": 1, "notas": [], "faltas": 0},
        {"codigo": "CAL", "nome": "Cálculo I", "semestre": "2023.2", "carga_horaria": 60,
         "total_avaliacoes": 2, "notas": [3.5, 4.0], "faltas": 6, "situacao_final": "reprovado_nota"},
        {"codigo": "CAL", "nome": "Cálculo I", "semestre": "2024.1", "carga_horaria": 60,
         "total_avaliacoes": 2, "notas": [8.0, 7.5], "faltas": 2, "situacao_final": "aprovado_direto"},
    ],
}
ALUNO = ResumoAluno.model_validate(RESUMO)


def test_por_disciplina_calcula_a_situacao_atual_com_as_regras():
    r = consultar_situacao_aluno(ALUNO, "apoio a decisao")  # sem acento e em minúsculas também acha

    [sad] = r["disciplinas"]
    assert sad["situacao"] == "prova_final"
    assert sad["media_parcial"] == 5.75
    assert sad["nota_necessaria_final"] == 6.25
    assert sad["regra"] == "Art. 23"
    assert sad["faltas_restantes"] == 11


def test_disciplina_do_historico_traz_as_duas_vezes_que_cursou():
    r = consultar_situacao_aluno(ALUNO, "Cálculo")

    assert [(d["semestre"], d["situacao"]) for d in r["disciplinas"]] == [("2024.1", "aprovado_direto"), ("2023.2", "reprovado_nota")]


def test_sem_disciplina_mostra_o_periodo_pedido():
    assert {d["codigo"] for d in consultar_situacao_aluno(ALUNO)["disciplinas"]} == {"SAD", "TC2"}
    assert {d["semestre"] for d in consultar_situacao_aluno(ALUNO, periodo="historico")["disciplinas"]} == {"2023.2", "2024.1"}
    assert len(consultar_situacao_aluno(ALUNO, periodo="tudo")["disciplinas"]) == 4


def test_disciplina_sem_todas_as_notas_esta_em_curso():
    [tcc] = consultar_situacao_aluno(ALUNO, "TC2")["disciplinas"]
    assert tcc["situacao"] == "cursando"
    assert tcc["avaliacoes"] == "0 de 1"


def test_disciplina_que_nao_existe_avisa_em_vez_de_inventar():
    r = consultar_situacao_aluno(ALUNO, "Física Quântica")
    assert r["disciplinas"] == [] and "aviso" in r


def test_agente_consulta_os_dados_e_monta_o_cartao(gemini):  # noqa: F811
    cliente = gemini(
        _chamada("consultar_situacao_aluno", disciplina="Sistemas de Apoio à Decisão"),
        _texto("Você está de prova final e precisa de 6,25 (Art. 23)."),
    )

    resposta = responder([{"role": "user", "content": "eu passei em SAD?"}], ALUNO)

    assert resposta.ferramenta == "consultar_situacao_aluno"
    assert resposta.dados_aluno == [{"disciplina": "Sistemas de Apoio à Decisão", "codigo": "SAD", "semestre": "2026.2",
                                     "notas": [5.5, 6.0], "faltas": 4, "situacao": "prova_final",
                                     "media_parcial": 5.75, "nota_necessaria_final": 6.25, "limite_faltas": 15}]
    assert cliente.chamadas == 2


def test_sem_dados_do_aluno_a_ferramenta_nao_e_oferecida(gemini, monkeypatch):  # noqa: F811
    import src.agent.core as core

    usadas = []
    original = core._gerar
    monkeypatch.setattr(core, "_gerar", lambda c, conteudos, ferramentas: usadas.append(len(ferramentas)) or original(c, conteudos, ferramentas))
    gemini(_texto("Para consultar sua situação, entre no Portal do Aluno."))

    responder([{"role": "user", "content": "eu passei?"}])

    assert usadas == [1]  # só as ferramentas gerais, sem a do aluno


def test_api_aceita_o_resumo_e_devolve_o_cartao(monkeypatch):
    from src.agent.core import RespostaAgente

    recebido = {}

    def falso(mensagens, aluno):
        recebido["aluno"] = aluno
        return RespostaAgente(texto="Prova final.", ferramenta="consultar_situacao_aluno",
                              dados_aluno=[{"disciplina": "Sistemas de Apoio à Decisão", "codigo": "SAD", "semestre": "2026.2",
                                            "notas": [5.5, 6.0], "faltas": 4, "situacao": "prova_final"}])

    monkeypatch.setattr(main, "get_settings", lambda: type("S", (), {"gemini_api_key": "x", "ia_token": "token-de-teste"})())
    monkeypatch.setattr(main, "responder", falso)

    r = client.post("/api/v1/chat", json={"session_id": "s", "messages": [{"role": "user", "content": "eu passei em SAD?"}], "aluno": RESUMO})

    assert r.status_code == 200
    assert r.json()["dados_aluno"][0]["situacao"] == "prova_final"
    assert recebido["aluno"].semestre_atual == "2026.2"


def test_api_recusa_resumo_com_dado_pessoal_a_mais():
    com_nome = {**RESUMO, "nome": "Ana Beatriz"}
    r = client.post("/api/v1/chat", json={"session_id": "s", "messages": [{"role": "user", "content": "oi"}], "aluno": com_nome})
    assert r.status_code == 422


def test_resumo_geral_traz_a_media_e_os_totais_ja_calculados():
    r = consultar_situacao_aluno(ALUNO, periodo="historico")["resumo_geral"]

    # concluídas: Cálculo I reprovado (3,5 e 4,0 → 3,75) e aprovado (8,0 e 7,5 → 7,75): média geral 5,75
    # semestre atual: só SAD tem nota lançada (5,5 e 6,0 → 5,75)
    assert r == {"disciplinas_concluidas": 2, "aprovacoes": 1, "reprovacoes": 1, "em_curso": 2, "media_geral_historico": 5.75,
                 "media_parcial_semestre_atual": 5.75, "disciplinas_com_nota_no_semestre_atual": 1}


def test_raciocinio_baixo_so_nos_modelos_gemini_3(monkeypatch):
    import src.agent.core as core
    from types import SimpleNamespace

    monkeypatch.setattr(core, "get_settings", lambda: SimpleNamespace(gemini_model="gemini-3.5-flash", gemini_thinking="low"))
    assert core._config([], "gemini-3.5-flash").thinking_config.thinking_level.name == "LOW"

    monkeypatch.setattr(core, "get_settings", lambda: SimpleNamespace(gemini_model="gemini-flash-lite-latest", gemini_thinking="low"))
    assert core._config([], "gemini-flash-lite-latest").thinking_config is None
