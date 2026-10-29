"""Situação de uma disciplina segundo o Regimento (Arts. 15 e 21 a 24): notas, prova final e faltas."""

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

from src.tools.calculator import calcular_nota_final

LIMITE_FALTAS = 0.25  # Art. 15: reprovado quem faltar a mais de 25% da carga horária

Situacao = Literal[
    "cursando",
    "aprovado_direto",
    "prova_final",
    "aprovado_final",
    "reprovado_final",
    "reprovado_nota",
    "reprovado_falta",
]


class ResultadoSituacao(BaseModel):
    model_config = ConfigDict(frozen=True)

    situacao: Situacao
    media_parcial: float | None = None
    nota_necessaria_final: float | None = None
    media_final: float | None = None
    limite_faltas: int
    faltas_restantes: int


def calcular_situacao(
    carga_horaria: int,
    faltas: int,
    notas: list[float] | None = None,
    total_avaliacoes: int = 2,
    prova_final: float | None = None,
) -> ResultadoSituacao:
    """A falta reprova independentemente das notas (Art. 15); sem todas as avaliações lançadas, a disciplina está em curso."""
    limite = int(carga_horaria * LIMITE_FALTAS)
    base = {"limite_faltas": limite, "faltas_restantes": max(0, limite - faltas)}

    media = nota_final = media_final = None
    notas = notas or []
    if notas and len(notas) >= total_avaliacoes:
        calculo = calcular_nota_final(notas)
        media, nota_final = calculo.media_parcial, calculo.nota_necessaria_final
        situacao = {"aprovado_direto": "aprovado_direto", "reprovado_direto": "reprovado_nota"}.get(calculo.status, "prova_final")
        if situacao == "prova_final" and prova_final is not None:
            media_final = (media + prova_final) / 2  # Art. 23, § 1º
            situacao = "aprovado_final" if media_final >= 6 else "reprovado_final"
    else:
        situacao = "cursando"

    if faltas > limite:
        situacao = "reprovado_falta"
    return ResultadoSituacao(situacao=situacao, media_parcial=media, nota_necessaria_final=nota_final, media_final=media_final, **base)


def calcular_limite_faltas(carga_horaria: int, faltas: int | None = None) -> dict:
    """Limite de faltas de qualquer carga horária (Art. 15: 25%), não só as de 40, 60 e 80 h listadas no Regimento."""
    if not 0 < carga_horaria <= 400 or (faltas is not None and not 0 <= faltas <= 400):
        raise ValueError("Carga horária ou faltas fora do esperado.")
    limite = int(carga_horaria * LIMITE_FALTAS)
    resultado = {"carga_horaria": carga_horaria, "limite_faltas": limite, "regra": "Art. 15"}
    if faltas is not None:
        resultado |= {"faltas": faltas, "faltas_restantes": max(0, limite - faltas), "reprovado_por_falta": faltas > limite}
    return resultado
