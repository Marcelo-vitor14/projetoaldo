"""Dados do próprio aluno, enviados pelo portal a cada pergunta (sem nome, matrícula ou e-mail).

O resumo inteiro fica aqui na API: o Gemini só recebe o que esta ferramenta devolve para a pergunta
feita (ex.: só a disciplina perguntada). A situação é calculada pelo código, nunca pela IA.
"""

import unicodedata
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

from src.tools.calculator import Nota
from src.tools.situacao import calcular_situacao

# Artigo do Regimento que sustenta cada situação, para a IA citar
REGRA = {
    "aprovado_direto": "Art. 22",
    "prova_final": "Art. 23",
    "aprovado_final": "Art. 23, § 1º",
    "reprovado_final": "Art. 23, § 1º",
    "reprovado_nota": "Art. 24",
    "reprovado_falta": "Art. 15",
    "cursando": "Art. 21",
}


class CursadaAluno(BaseModel):
    model_config = ConfigDict(extra="forbid")

    codigo: str = Field(max_length=20)
    nome: str = Field(max_length=120)
    semestre: str = Field(max_length=10)
    carga_horaria: int = Field(gt=0, le=400)
    total_avaliacoes: int = Field(ge=1, le=10)
    notas: list[Nota] = Field(default_factory=list, max_length=10)
    prova_final: Nota | None = None
    faltas: int = Field(ge=0, le=400)
    situacao_final: str | None = Field(default=None, max_length=20)  # vazia = em curso


class ResumoAluno(BaseModel):
    model_config = ConfigDict(extra="forbid")

    curso: str = Field(max_length=120)
    periodo_atual: int = Field(ge=1, le=20)
    semestre_atual: str = Field(max_length=10)
    disciplinas: list[CursadaAluno] = Field(default_factory=list, max_length=150)
    # do curso, para o progresso (opcionais: sem eles, o progresso sai sem percentual e sem prazo)
    ingresso: str | None = Field(default=None, max_length=10)
    duracao_semestres: int | None = Field(default=None, ge=1, le=20)
    total_disciplinas: int | None = Field(default=None, ge=1, le=150)
    carga_horaria_total: int | None = Field(default=None, ge=1, le=20000)


def _normalizar(texto: str) -> str:
    sem_acento = unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode()
    return " ".join(sem_acento.lower().split())


def _descrever(c: CursadaAluno, semestre_atual: str) -> dict:
    if c.situacao_final is None:  # em curso: calcula agora, com as regras do regimento
        r = calcular_situacao(c.carga_horaria, c.faltas, c.notas, c.total_avaliacoes, c.prova_final)
        detalhe = {
            "situacao": r.situacao,
            "media_parcial": r.media_parcial,
            "nota_necessaria_final": r.nota_necessaria_final,
            "limite_faltas": r.limite_faltas,
            "faltas_restantes": r.faltas_restantes,
        }
    else:  # histórico: situação já registrada pela secretaria
        media = sum(c.notas) / len(c.notas) if c.notas else None
        detalhe = {"situacao": c.situacao_final, "media_parcial": media}
    return {
        "disciplina": c.nome,
        "codigo": c.codigo,
        "semestre": c.semestre,
        "em_curso": c.semestre == semestre_atual and c.situacao_final is None,
        "carga_horaria": c.carga_horaria,
        "avaliacoes": f"{len(c.notas)} de {c.total_avaliacoes}",
        "notas": c.notas,
        "faltas": c.faltas,
        "regra": REGRA.get(detalhe["situacao"]),
        "regra_faltas": "Art. 15",  # limite de faltas (25% da carga horária): para a IA não citar o artigo errado
        **detalhe,
    }


def _media_final(c: CursadaAluno) -> float | None:
    if not c.notas:
        return None
    media = sum(c.notas) / len(c.notas)
    return (media + c.prova_final) / 2 if c.prova_final is not None else media  # Art. 23, § 1º


def resumo_geral(resumo: ResumoAluno) -> dict:
    """Totais e a média geral do histórico, calculados aqui (a IA só explica).

    A média geral é a média simples das médias finais das disciplinas já concluídas; não é regra do Regimento.
    """
    concluidas = [c for c in resumo.disciplinas if c.situacao_final is not None]
    medias = [m for m in (_media_final(c) for c in concluidas) if m is not None]
    # semestre atual: média das notas já lançadas em cada disciplina (parcial, ainda pode mudar)
    atuais = [sum(c.notas) / len(c.notas) for c in resumo.disciplinas if c.situacao_final is None and c.notas]
    return {
        "disciplinas_concluidas": len(concluidas),
        "aprovacoes": sum(c.situacao_final.startswith("aprovado") for c in concluidas),
        "reprovacoes": sum(c.situacao_final.startswith("reprovado") for c in concluidas),
        "em_curso": sum(c.situacao_final is None for c in resumo.disciplinas),
        "media_geral_historico": round(sum(medias) / len(medias), 2) if medias else None,
        "media_parcial_semestre_atual": round(sum(atuais) / len(atuais), 2) if atuais else None,
        "disciplinas_com_nota_no_semestre_atual": len(atuais),
    }


def consultar_situacao_aluno(
    resumo: ResumoAluno,
    disciplina: str | None = None,
    periodo: Literal["atual", "historico", "tudo"] = "atual",
) -> dict:
    """Com disciplina: tudo dessa disciplina (atual e histórico). Sem disciplina: o período pedido."""
    if disciplina:
        alvo = _normalizar(disciplina)
        itens = [c for c in resumo.disciplinas if alvo in _normalizar(c.nome) or alvo == _normalizar(c.codigo)]
    elif periodo == "historico":
        itens = [c for c in resumo.disciplinas if c.situacao_final is not None]
    elif periodo == "tudo":
        itens = list(resumo.disciplinas)
    else:
        itens = [c for c in resumo.disciplinas if c.situacao_final is None]

    resultado = {
        "curso": resumo.curso,
        "periodo_atual": resumo.periodo_atual,
        "semestre_atual": resumo.semestre_atual,
        "resumo_geral": resumo_geral(resumo),
        "disciplinas": [_descrever(c, resumo.semestre_atual) for c in sorted(itens, key=lambda c: c.semestre, reverse=True)],
    }
    if not itens:
        resultado["aviso"] = "Nenhuma disciplina encontrada nos dados deste aluno."
    return resultado
