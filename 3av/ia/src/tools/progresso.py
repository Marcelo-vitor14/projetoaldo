"""Progresso do aluno no curso: carga horária cumprida, prazo máximo, TCC, estágio, jubilamento e bolsa.

Tudo calculado aqui, com as regras do Regimento (Arts. 32, 39, 40 e 41) e do Manual (Seção 10); a IA só explica.
"""

import math
import re
from collections import Counter

from src.tools.dados_aluno import ResumoAluno, resumo_geral

FORMATO_SEMESTRE = re.compile(r"^\d{4}\.[12]$")


def _indice(semestre: str | None) -> int | None:
    """'2023.1' → um número que cresce um por semestre (para contar semestres entre duas datas)."""
    if not semestre or not FORMATO_SEMESTRE.match(semestre):
        return None
    ano, s = semestre.split(".")
    return int(ano) * 2 + int(s) - 1


def _rotulo(indice: int) -> str:
    return f"{indice // 2}.{indice % 2 + 1}"


def progresso_curso(resumo: ResumoAluno) -> dict:
    aprovadas = {c.codigo: c for c in resumo.disciplinas if (c.situacao_final or "").startswith("aprovado")}
    ch_cumprida = sum(c.carga_horaria for c in aprovadas.values())
    pct = round(100 * ch_cumprida / resumo.carga_horaria_total, 1) if resumo.carga_horaria_total else None

    # Prazo (Art. 40): uma vez e meia a duração padrão. Conta os semestres desde o ingresso (trancamentos não estão nos dados).
    inicio, atual = _indice(resumo.ingresso), _indice(resumo.semestre_atual)
    cursados = atual - inicio + 1 if inicio is not None and atual is not None else resumo.periodo_atual
    prazo = math.ceil(resumo.duracao_semestres * 1.5) if resumo.duracao_semestres else None
    prazo_info = None
    if prazo:
        prazo_info = {
            "duracao_padrao_semestres": resumo.duracao_semestres,
            "prazo_maximo_semestres": prazo,
            "semestres_cursados": cursados,
            "semestres_restantes_no_prazo": max(0, prazo - cursados),
            "ultimo_semestre_no_prazo": _rotulo(inicio + prazo - 1) if inicio is not None else None,
            "regra": "Art. 40",
            "obs": "Conta os semestres desde o ingresso; semestres trancados não estão nestes dados.",
        }

    # TCC (Art. 32): a partir do 7º semestre e com 75% da carga horária
    falta_tcc = []
    if resumo.periodo_atual < 7:
        falta_tcc.append(f"estar no 7º semestre (está no {resumo.periodo_atual}º)")
    if pct is not None and pct < 75:
        falta_tcc.append(f"ter 75% da carga horária (tem {pct}%)")

    # Jubilamento (Art. 41): 3 reprovações na mesma disciplina, ou passar do prazo máximo
    reprovacoes = Counter(c.codigo for c in resumo.disciplinas if (c.situacao_final or "").startswith("reprovado"))
    nomes = {c.codigo: c.nome for c in resumo.disciplinas}
    repetidas = [{"disciplina": nomes[cod], "reprovacoes": n} for cod, n in reprovacoes.most_common()]
    passou_prazo = bool(prazo and cursados > prazo)

    # Bolsa de monitoria (Manual, Seção 10): média geral ≥ 7,0 e sem reprovação por falta no semestre anterior
    geral = resumo_geral(resumo)
    anterior = _rotulo(atual - 1) if atual is not None else None
    falta_anterior = any(c.semestre == anterior and c.situacao_final == "reprovado_falta" for c in resumo.disciplinas)
    media = geral["media_geral_historico"]

    return {
        "curso": resumo.curso,
        "periodo_atual": resumo.periodo_atual,
        "semestre_atual": resumo.semestre_atual,
        "disciplinas_aprovadas": len(aprovadas),
        "total_disciplinas": resumo.total_disciplinas,
        "disciplinas_que_faltam": resumo.total_disciplinas - len(aprovadas) if resumo.total_disciplinas else None,
        "carga_horaria_cumprida": ch_cumprida,
        "carga_horaria_total": resumo.carga_horaria_total,
        "percentual_carga_horaria": pct,
        "prazo": prazo_info,
        "tcc": {"pode_matricular": not falta_tcc, "falta": falta_tcc, "regra": "Art. 32"},
        "estagio": {
            "pode_iniciar": resumo.periodo_atual >= 5,
            "falta": [] if resumo.periodo_atual >= 5 else [f"estar no 5º semestre (está no {resumo.periodo_atual}º)"],
            "regra": "Art. 39",
        },
        "jubilamento": {
            "risco": passou_prazo or any(r["reprovacoes"] >= 3 for r in repetidas),
            "atencao": [r for r in repetidas if r["reprovacoes"] == 2],
            "reprovacoes_por_disciplina": repetidas,
            "passou_do_prazo": passou_prazo,
            "regra": "Art. 41 (3 reprovações na mesma disciplina ou passar do prazo máximo)",
        },
        "bolsa_monitoria": {
            "atende": media is not None and media >= 7 and not falta_anterior,
            "media_geral": media,
            "reprovacao_por_falta_no_semestre_anterior": falta_anterior,
            "regra": "Manual do Aluno, Seção 10 (média geral ≥ 7,0 e sem reprovação por falta no semestre anterior)",
        },
    }
