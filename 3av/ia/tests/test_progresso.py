"""Contas que a IA não faz de cabeça: progresso no curso, nota para a meta e limite de faltas."""

import pytest

from src.agent.core import responder
from src.tools.calculator import calcular_nota_para_meta
from src.tools.dados_aluno import ResumoAluno
from src.tools.progresso import progresso_curso
from src.tools.situacao import calcular_limite_faltas
from tests.test_agent_fluxo import _chamada, _texto, gemini  # noqa: F401 (fixture)
from tests.test_dados_aluno import RESUMO

CURSO = {"ingresso": "2023.1", "duracao_semestres": 8, "total_disciplinas": 40, "carga_horaria_total": 2600}


def _aluno(**mudancas) -> ResumoAluno:
    return ResumoAluno.model_validate(RESUMO | CURSO | mudancas)


def _cursada(codigo, semestre, situacao, carga=60, notas=(7.0, 7.0)):
    return {"codigo": codigo, "nome": f"Disciplina {codigo}", "semestre": semestre, "carga_horaria": carga,
            "total_avaliacoes": 2, "notas": list(notas), "faltas": 0, "situacao_final": situacao}


def test_progresso_conta_so_aprovacoes_distintas_e_o_prazo_desde_o_ingresso():
    p = progresso_curso(_aluno())

    # Cálculo I: reprovado e depois aprovado conta uma vez só (60 h de 2600)
    assert (p["disciplinas_aprovadas"], p["disciplinas_que_faltam"]) == (1, 39)
    assert (p["carga_horaria_cumprida"], p["percentual_carga_horaria"]) == (60, 2.3)
    # 2023.1 a 2026.2 = 8 semestres; prazo de 12 (Art. 40) vai até 2028.2
    assert p["prazo"] | {"obs": None} == {"duracao_padrao_semestres": 8, "prazo_maximo_semestres": 12, "semestres_cursados": 8,
                                         "semestres_restantes_no_prazo": 4, "ultimo_semestre_no_prazo": "2028.2",
                                         "regra": "Art. 40", "obs": None}


def test_tcc_exige_7o_semestre_e_75_por_cento_e_estagio_o_5o():
    p = progresso_curso(_aluno())
    assert p["tcc"] == {"pode_matricular": False, "falta": ["ter 75% da carga horária (tem 2.3%)"], "regra": "Art. 32"}
    assert p["estagio"]["pode_iniciar"] is True

    calouro = progresso_curso(_aluno(periodo_atual=3))
    assert calouro["tcc"]["falta"][0] == "estar no 7º semestre (está no 3º)"
    assert calouro["estagio"] == {"pode_iniciar": False, "falta": ["estar no 5º semestre (está no 3º)"], "regra": "Art. 39"}


def test_jubilamento_com_tres_reprovacoes_na_mesma_disciplina_e_atencao_com_duas():
    duas = [_cursada("EST", s, "reprovado_nota") for s in ("2024.1", "2024.2")]
    p = progresso_curso(_aluno(disciplinas=RESUMO["disciplinas"] + duas))
    assert p["jubilamento"]["risco"] is False
    assert p["jubilamento"]["atencao"] == [{"disciplina": "Disciplina EST", "reprovacoes": 2}]

    tres = duas + [_cursada("EST", "2025.1", "reprovado_falta")]
    assert progresso_curso(_aluno(disciplinas=RESUMO["disciplinas"] + tres))["jubilamento"]["risco"] is True


def test_jubilamento_por_passar_do_prazo_maximo():
    p = progresso_curso(_aluno(ingresso="2020.1"))  # 2020.1 a 2026.2 = 14 semestres, prazo de 12
    assert (p["prazo"]["semestres_cursados"], p["jubilamento"]["passou_do_prazo"], p["jubilamento"]["risco"]) == (14, True, True)


def test_bolsa_pede_media_7_e_nenhuma_reprovacao_por_falta_no_semestre_anterior():
    boas = [_cursada(f"B{i}", "2025.2", "aprovado_direto", notas=(9.0, 9.0)) for i in range(3)]
    assert progresso_curso(_aluno(disciplinas=boas))["bolsa_monitoria"]["atende"] is True

    falta = boas + [_cursada("FAL", "2026.1", "reprovado_falta", notas=(9.0, 9.0))]  # 2026.1 é o anterior a 2026.2
    bolsa = progresso_curso(_aluno(disciplinas=falta))["bolsa_monitoria"]
    assert (bolsa["atende"], bolsa["reprovacao_por_falta_no_semestre_anterior"]) == (False, True)

    assert progresso_curso(_aluno())["bolsa_monitoria"]["atende"] is False  # média geral 5,75


def test_sem_os_dados_do_curso_o_progresso_nao_inventa_prazo_nem_percentual():
    p = progresso_curso(ResumoAluno.model_validate(RESUMO))
    assert (p["prazo"], p["percentual_carga_horaria"], p["disciplinas_que_faltam"]) == (None, None, None)


@pytest.mark.parametrize(("notas", "total", "meta", "precisa", "possivel", "garantida"), [
    ([6.0, 5.0], 3, 7.0, 10.0, True, False),   # 7×3 − 11 = 10
    ([5.0, 4.0], 3, 7.0, 12.0, False, False),  # precisaria de 12: não dá para passar direto
    ([9.0, 10.0], 3, 4.0, 0.0, True, True),    # já garantiu a meta
    ([], 2, 7.0, 7.0, True, False),            # nenhuma nota ainda
    ([6.0], 3, 7.0, 7.5, True, False),         # duas avaliações faltando: 7,5 em cada
])
def test_nota_para_meta(notas, total, meta, precisa, possivel, garantida):
    r = calcular_nota_para_meta(notas, total, meta)
    assert (r.nota_necessaria_em_cada, r.possivel, r.ja_garantida) == (precisa, possivel, garantida)


def test_nota_para_meta_recusa_quando_nao_falta_avaliacao_ou_nota_invalida():
    with pytest.raises(ValueError):
        calcular_nota_para_meta([7.0, 8.0], 2)
    with pytest.raises(ValueError):
        calcular_nota_para_meta([11.0], 2)


@pytest.mark.parametrize(("carga", "limite"), [(40, 10), (60, 15), (72, 18), (80, 20), (30, 7)])
def test_limite_de_faltas_de_qualquer_carga_horaria(carga, limite):
    assert calcular_limite_faltas(carga)["limite_faltas"] == limite


def test_limite_de_faltas_com_as_faltas_do_aluno():
    assert calcular_limite_faltas(72, 19) == {"carga_horaria": 72, "limite_faltas": 18, "regra": "Art. 15",
                                              "faltas": 19, "faltas_restantes": 0, "reprovado_por_falta": True}


def test_agente_usa_as_ferramentas_novas(gemini):  # noqa: F811
    gemini(_chamada("consultar_progresso_curso"), _texto("Você cumpriu 2,3% da carga horária."))
    assert responder([{"role": "user", "content": "quanto do curso eu já fiz?"}], _aluno()).ferramenta == "consultar_progresso_curso"

    gemini(_chamada("calcular_nota_para_meta", notas=[6, 5], total_avaliacoes=3), _texto("Você precisa de 10."))
    assert responder([{"role": "user", "content": "quanto preciso na P3?"}]).ferramenta == "calcular_nota_para_meta"

    gemini(_chamada("calcular_nota_para_meta", notas=[6, 5], total_avaliacoes=2), _texto("Já saíram todas."))
    assert responder([{"role": "user", "content": "quanto preciso?"}]).texto == "Já saíram todas."  # erro vira aviso para a IA

    gemini(_chamada("calcular_limite_faltas", carga_horaria=72), _texto("18 horas."))
    assert responder([{"role": "user", "content": "faltas em 72 h?"}]).ferramenta == "calcular_limite_faltas"


def test_progresso_sem_login_nao_e_oferecido_nem_executado(gemini):  # noqa: F811
    gemini(_chamada("consultar_progresso_curso"), _texto("Entre no Portal do Aluno."))
    assert responder([{"role": "user", "content": "posso fazer TCC?"}]).ferramenta is None
