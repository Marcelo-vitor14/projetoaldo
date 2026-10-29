import pytest
from pydantic import ValidationError

from src.tools.calculator import calcular_nota_final


@pytest.mark.parametrize(
    ("p1", "p2", "media", "status", "nota_final"),
    [
        (5.5, 6.0, 5.75, "prova_final", 6.25),
        (7.0, 7.0, 7.0, "aprovado_direto", None),
        (3.9, 4.0, 3.95, "reprovado_direto", None),
        (4.0, 4.0, 4.0, "prova_final", 8.0),
    ],
)
def test_calcular_nota_final(p1, p2, media, status, nota_final):
    result = calcular_nota_final(p1, p2)

    assert result.media_parcial == media
    assert result.status == status
    assert result.nota_necessaria_final == nota_final


@pytest.mark.parametrize("nota", [-0.1, 10.1])
def test_rejeita_notas_fora_da_escala(nota):
    with pytest.raises(ValidationError):
        calcular_nota_final(nota, 5.0)


@pytest.mark.parametrize("nota", [float("nan"), float("inf"), float("-inf")])
def test_rejeita_notas_nao_finitas(nota):
    with pytest.raises(ValidationError):
        calcular_nota_final(nota, 5.0)


def test_media_exatamente_sete_com_notas_diferentes_aprova_direto():
    result = calcular_nota_final(6.5, 7.5)

    assert result.media_parcial == 7.0
    assert result.status == "aprovado_direto"
    assert result.nota_necessaria_final is None


def test_extremos_da_escala_zero_e_dez():
    assert calcular_nota_final(0, 0).status == "reprovado_direto"
    assert calcular_nota_final(0, 0).media_parcial == 0
    assert calcular_nota_final(10, 10).status == "aprovado_direto"
    assert calcular_nota_final(10, 10).media_parcial == 10


def test_media_logo_abaixo_de_sete_vai_para_a_final():
    result = calcular_nota_final(6.9, 7.0)

    assert result.status == "prova_final"
    assert result.nota_necessaria_final == pytest.approx(5.05)


def test_resultado_e_imutavel():
    result = calcular_nota_final(5.5, 6.0)

    with pytest.raises(ValidationError):
        result.media_parcial = 10
