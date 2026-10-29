"""Cálculo determinístico da média parcial e nota necessária na final."""

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class ResultadoCalculo(BaseModel):
    """Resultado validado segundo as regras acadêmicas documentadas."""

    model_config = ConfigDict(frozen=True)

    p1: float = Field(ge=0, le=10)
    p2: float = Field(ge=0, le=10)
    media_parcial: float
    status: Literal["aprovado_direto", "prova_final", "reprovado_direto"]
    nota_necessaria_final: float | None = None


def calcular_nota_final(p1: float, p2: float) -> ResultadoCalculo:
    """Calcula MP=(P1+P2)/2 e aplica os limites definidos na Spec."""
    dados = ResultadoCalculo.model_validate(
        {"p1": p1, "p2": p2, "media_parcial": 0, "status": "prova_final"}
    )
    media = (dados.p1 + dados.p2) / 2

    if media >= 7:
        status = "aprovado_direto"
        nota_final = None
    elif media >= 4:
        status = "prova_final"
        nota_final = 12 - media
    else:
        status = "reprovado_direto"
        nota_final = None

    return ResultadoCalculo(
        p1=dados.p1,
        p2=dados.p2,
        media_parcial=media,
        status=status,
        nota_necessaria_final=nota_final,
    )
