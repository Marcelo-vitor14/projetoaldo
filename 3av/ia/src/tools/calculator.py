"""Cálculo determinístico da média parcial e nota necessária na final."""

from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field

Nota = Annotated[float, Field(ge=0, le=10, allow_inf_nan=False)]


class ResultadoCalculo(BaseModel):
    """Resultado validado segundo as regras acadêmicas documentadas."""

    model_config = ConfigDict(frozen=True)

    notas: list[Nota] = Field(min_length=1, max_length=10)
    media_parcial: float
    status: Literal["aprovado_direto", "prova_final", "reprovado_direto"]
    nota_necessaria_final: float | None = None


def calcular_nota_final(notas: list[float]) -> ResultadoCalculo:
    """Calcula a média parcial (média simples de todas as avaliações) e aplica os limites definidos na Spec.

    Com duas notas é o MP = (P1 + P2) / 2 do Art. 21; a disciplina pode ter quantas avaliações quiser.
    """
    dados = ResultadoCalculo.model_validate({"notas": notas, "media_parcial": 0, "status": "prova_final"})
    media = sum(dados.notas) / len(dados.notas)

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
        notas=dados.notas,
        media_parcial=media,
        status=status,
        nota_necessaria_final=nota_final,
    )


class ResultadoMeta(BaseModel):
    model_config = ConfigDict(frozen=True)

    notas: list[Nota]
    total_avaliacoes: int
    faltam_avaliacoes: int
    meta: float
    nota_necessaria_em_cada: float  # a mesma nota em cada avaliação que falta
    ja_garantida: bool
    possivel: bool
    regras: str = (
        "Art. 21: a média parcial é a média simples das avaliações; Art. 22: média ≥ 7,0 aprova direto; "
        "Art. 23: média de 4,0 a 6,9 vai para a Prova Final; Art. 24: média abaixo de 4,0 reprova direto"
    )


def calcular_nota_para_meta(notas: list[float], total_avaliacoes: int, meta: float = 7.0) -> ResultadoMeta:
    """Quanto tirar, em cada avaliação que falta, para a média parcial chegar à meta (conta inversa da média simples).

    Meta 7,0 = passar direto (Art. 22); 4,0 = escapar da reprovação direta (Art. 24).
    """
    notas = [float(n) for n in notas]
    ResultadoCalculo.model_validate({"notas": notas or [0], "media_parcial": 0, "status": "prova_final"})  # 0 a 10
    if not 0 <= meta <= 10:
        raise ValueError("A meta precisa estar entre 0 e 10.")
    if not len(notas) < total_avaliacoes <= 10:
        raise ValueError("Não falta nenhuma avaliação: use calcular_nota_final.")
    faltam = total_avaliacoes - len(notas)
    necessaria = (meta * total_avaliacoes - sum(notas)) / faltam
    return ResultadoMeta(
        notas=notas,
        total_avaliacoes=total_avaliacoes,
        faltam_avaliacoes=faltam,
        meta=meta,
        nota_necessaria_em_cada=round(max(necessaria, 0), 2),
        ja_garantida=necessaria <= 0,
        possivel=necessaria <= 10,
    )
