from src.agent.core import RespostaAgente, _executar, _fontes_citadas


def test_so_mantem_fontes_citadas_na_resposta():
    fontes = [
        {"documento": "R.pdf", "artigo": "Art. 15", "pagina": 2, "trecho": "..."},
        {"documento": "R.pdf", "artigo": "Art. 1º", "pagina": 1, "trecho": "..."},
        {"documento": "M.pdf", "artigo": "Seção 7", "pagina": 2, "trecho": "..."},
    ]
    resposta = RespostaAgente(texto="O limite é de 15 horas (Art. 15, p. 2).", fontes=fontes)

    assert [f["artigo"] for f in _fontes_citadas(resposta)] == ["Art. 15"]


def test_ferramenta_de_calculo_devolve_resultado_da_calculadora():
    resposta = RespostaAgente(texto="")

    resultado = _executar("calcular_nota_final", {"p1": 5.5, "p2": 6.0}, resposta)

    assert resultado["media_parcial"] == 5.75
    assert resultado["nota_necessaria_final"] == 6.25
    assert resposta.ferramenta == "calcular_nota_final"


def test_ferramenta_de_calculo_recusa_nota_invalida():
    resultado = _executar("calcular_nota_final", {"p1": 15, "p2": 6}, RespostaAgente(texto=""))

    assert "erro" in resultado
