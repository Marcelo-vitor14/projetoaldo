"""Base versionada: conferir PDF, montar a base inteira, trocar só no fim e voltar para a anterior."""

import io
import json
from pathlib import Path
from types import SimpleNamespace

import pytest
from fastapi.testclient import TestClient
from pypdf import PdfWriter

import src.rag.ingest as ingest
from src.api.main import app
from src.rag import vectorstore
from src.rag.vectorstore import estado_base, get_collection

REGIMENTO = Path("data/raw/Regimento_Academico_IUVA_2026.pdf")
MANUAL = Path("data/raw/Manual_do_Aluno_IUVA_2026.pdf")
client = TestClient(app, headers={"X-IA-Token": "token-de-teste"})
precisa_dos_pdfs = pytest.mark.skipif(not (REGIMENTO.exists() and MANUAL.exists()), reason="PDFs de teste ausentes em data/raw")


@pytest.fixture(autouse=True)
def base_limpa(monkeypatch, tmp_path):
    """Cada teste com o seu banco vetorial e embeddings falsos (sem internet)."""
    monkeypatch.setattr(ingest, "embed_textos", lambda textos, _tarefa: [[0.1] * 768 for _ in textos])
    monkeypatch.setattr(vectorstore, "get_settings", lambda: SimpleNamespace(chroma_persist_directory=str(tmp_path / "vectordb")))


def _pdf_em_branco() -> bytes:
    escritor = PdfWriter()
    escritor.add_blank_page(width=200, height=200)
    saida = io.BytesIO()
    escritor.write(saida)
    return saida.getvalue()


def test_sem_artigos_corta_por_paragrafos_e_cita_documento_e_pagina():
    paginas = ["Edital de Bolsas 2027\n" + "Linha de texto do edital. " * 30, "Inscrições até 10 de março."]

    trechos = ingest._por_paragrafos(paginas, "Edital de Bolsas")

    assert {t["pagina"] for t in trechos} == {1, 2}
    assert all(t["artigo"] == "Edital de Bolsas" for t in trechos)


def test_extrair_usa_paragrafos_quando_nao_acha_artigo(monkeypatch):
    paginas = [SimpleNamespace(extract_text=lambda: "Comunicado da secretaria\nO portal ficará fora do ar no sábado.")]
    monkeypatch.setattr(ingest, "PdfReader", lambda _pdf: SimpleNamespace(pages=paginas))

    assert ingest.extrair_trechos(Path("x.pdf"), "Comunicado")[0]["artigo"] == "Comunicado"


def test_pdf_sem_texto_e_recusado(tmp_path):
    pdf = tmp_path / "escaneado.pdf"
    pdf.write_bytes(_pdf_em_branco())

    with pytest.raises(ingest.PdfSemTexto):
        ingest.extrair_trechos(pdf)


@precisa_dos_pdfs
def test_conferir_pdf_nao_gera_embeddings(monkeypatch):
    monkeypatch.setattr(ingest, "embed_textos", lambda *_: pytest.fail("não devia gerar embeddings"))

    assert ingest.verificar_pdf(REGIMENTO, "Regimento") == {"trechos": 48, "com_artigos": True}


@precisa_dos_pdfs
def test_montar_base_troca_a_ativa_e_guarda_a_anterior():
    assert estado_base()["versao"] == 1  # sem publicação: a coleção do MVP

    r = ingest.montar_base([(REGIMENTO, "doc-1", "Regimento"), (MANUAL, "doc-2", "Manual")], 2)

    assert r["trechos"] == 63 and [d["trechos"] for d in r["documentos"]] == [48, 15]
    assert estado_base() == {"ativa": "base_v2", "versao": 2, "anterior": "regimento_academico", "versao_anterior": 1}
    assert get_collection().count() == 63

    ingest.montar_base([(MANUAL, "doc-2", "Manual")], 3)  # o regimento saiu
    assert estado_base()["anterior"] == "base_v2" and get_collection().count() == 15


@precisa_dos_pdfs
def test_falha_no_meio_nao_mexe_na_base_ativa(tmp_path, monkeypatch):
    ingest.montar_base([(REGIMENTO, "doc-1", "Regimento")], 2)
    ruim = tmp_path / "ruim.pdf"
    ruim.write_bytes(_pdf_em_branco())

    with pytest.raises(ingest.PdfSemTexto, match="Edital"):
        ingest.montar_base([(REGIMENTO, "doc-1", "Regimento"), (ruim, "doc-3", "Edital")], 3)

    def gemini_caiu(*_):
        raise RuntimeError("503")

    monkeypatch.setattr(ingest, "embed_textos", gemini_caiu)
    with pytest.raises(RuntimeError):
        ingest.montar_base([(MANUAL, "doc-2", "Manual")], 3)

    assert estado_base()["ativa"] == "base_v2" and get_collection().count() == 48


@precisa_dos_pdfs
def test_voltar_restaura_a_anterior_e_so_uma_vez():
    ingest.montar_base([(REGIMENTO, "doc-1", "Regimento")], 2)
    ingest.montar_base([(MANUAL, "doc-2", "Manual")], 3)

    assert ingest.voltar_base()["versao"] == 2
    assert get_collection().count() == 48
    with pytest.raises(ingest.SemVersaoAnterior):
        ingest.voltar_base()


def test_base_vazia_e_recusada():
    with pytest.raises(ValueError):
        ingest.montar_base([], 2)


def test_so_uma_montagem_por_vez():
    ingest._trava.acquire()
    try:
        with pytest.raises(ingest.BaseOcupada):
            ingest.montar_base([(REGIMENTO, "doc-1", "Regimento")], 2)
    finally:
        ingest._trava.release()


@precisa_dos_pdfs
def test_api_confere_publica_e_volta():
    conferido = client.post("/api/v1/documentos/verificar", data={"nome": "Regimento"}, files={"arquivo": ("r.pdf", REGIMENTO.read_bytes(), "application/pdf")})
    assert conferido.status_code == 200 and conferido.json()["trechos"] == 48

    docs = json.dumps([{"documento_id": "portal-1", "nome": "Regimento"}, {"documento_id": "portal-2", "nome": "Manual"}])
    r = client.post("/api/v1/base", data={"versao": "2", "documentos": docs},
                    files=[("arquivos", ("r.pdf", REGIMENTO.read_bytes(), "application/pdf")), ("arquivos", ("m.pdf", MANUAL.read_bytes(), "application/pdf"))])
    assert r.status_code == 200 and r.json()["trechos"] == 63
    assert client.get("/api/v1/base").json()["versao"] == 2

    assert client.post("/api/v1/base/voltar").json()["versao"] == 1
    assert client.post("/api/v1/base/voltar").status_code == 409  # não há outra anterior


def test_api_recusa_pdf_ruim_na_conferencia():
    texto = client.post("/api/v1/documentos/verificar", data={"nome": "X"}, files={"arquivo": ("a.pdf", b"ola", "application/pdf")})
    assert texto.status_code == 422 and "não é um PDF" in texto.json()["detail"]

    branco = client.post("/api/v1/documentos/verificar", data={"nome": "X"}, files={"arquivo": ("b.pdf", _pdf_em_branco(), "application/pdf")})
    assert branco.status_code == 422 and "escaneado" in branco.json()["detail"]


def test_api_exige_arquivo_para_cada_documento():
    docs = json.dumps([{"documento_id": "a", "nome": "A"}, {"documento_id": "b", "nome": "B"}])
    r = client.post("/api/v1/base", data={"versao": "2", "documentos": docs}, files=[("arquivos", ("a.pdf", b"%PDF", "application/pdf"))])
    assert r.status_code == 422


def test_rotas_da_base_exigem_token():
    assert TestClient(app).get("/api/v1/base").status_code == 401
    assert TestClient(app).post("/api/v1/base/voltar").status_code == 401
