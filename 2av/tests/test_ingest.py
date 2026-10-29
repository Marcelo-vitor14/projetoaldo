from pathlib import Path

import pytest

from src.rag.ingest import extrair_trechos

REGIMENTO = Path("data/raw/Regimento_Academico_IUVA_2026.pdf")
MANUAL = Path("data/raw/Manual_do_Aluno_IUVA_2026.pdf")


@pytest.mark.skipif(not REGIMENTO.exists(), reason="PDF do regimento ausente em data/raw")
def test_regimento_e_cortado_por_artigo_com_pagina():
    trechos = {t["artigo"]: t for t in extrair_trechos(REGIMENTO)}

    assert len(trechos) == 48
    assert trechos["Art. 15"]["pagina"] == 2
    assert "75%" in trechos["Art. 15"]["texto"]
    assert trechos["Art. 15"]["capitulo"].startswith("CAPÍTULO IV")
    assert "DOCUMENTO FICTÍCIO" not in trechos["Art. 15"]["texto"]


@pytest.mark.skipif(not MANUAL.exists(), reason="PDF do manual ausente em data/raw")
def test_manual_e_cortado_por_secao():
    trechos = {t["artigo"]: t for t in extrair_trechos(MANUAL)}

    assert len(trechos) == 15
    assert "3 livros" in trechos["Seção 6"]["texto"]
