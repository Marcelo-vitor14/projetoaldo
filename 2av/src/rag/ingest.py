"""Ingestão: lê os PDFs de data/raw, corta por artigo ou seção e grava no ChromaDB.

Uso: python -m src.rag.ingest
"""

import hashlib
import re
from pathlib import Path

from pypdf import PdfReader

from src.config import get_settings
from src.rag.vectorstore import embed_textos, reset_collection

# Início de um trecho: "Art. 15." ou "Art. 1º" no Regimento; "Seção 6 — Biblioteca" no Manual.
_INICIO_RE = re.compile(r"^(Art\.\s*\d+[º°]?|Seção\s+\d+)")
_CAPITULO_RE = re.compile(r"^CAP[IÍ]TULO\s+[IVXLC]+", re.IGNORECASE)
# Cabeçalho e rodapé repetidos em toda página.
_RUIDO_RE = re.compile(r"^DOCUMENTO FICTÍCIO|página \d+$")


def extrair_trechos(pdf: Path) -> list[dict]:
    """Devolve um trecho por artigo/seção, com documento, capítulo, artigo, página e texto."""
    trechos: list[dict] = []
    atual: dict | None = None
    capitulo = ""
    for numero_pagina, pagina in enumerate(PdfReader(pdf).pages, start=1):
        for linha in (pagina.extract_text() or "").splitlines():
            linha = linha.strip()
            if not linha or _RUIDO_RE.search(linha):
                continue
            if _CAPITULO_RE.match(linha):
                capitulo = linha
                continue
            inicio = _INICIO_RE.match(linha)
            if inicio:
                atual = {
                    "documento": pdf.name,
                    "capitulo": capitulo,
                    "artigo": re.sub(r"\s+", " ", inicio.group(1)),
                    "pagina": numero_pagina,
                    "texto": linha,
                }
                trechos.append(atual)
            elif atual:
                atual["texto"] += " " + linha
    return trechos


def _id_trecho(trecho: dict) -> str:
    chave = f"{trecho['documento']}|{trecho['artigo']}|{trecho['pagina']}"
    return hashlib.sha256(chave.encode()).hexdigest()[:16]


def main() -> None:
    settings = get_settings()
    if not settings.gemini_api_key:
        raise SystemExit("Defina GEMINI_API_KEY no .env antes da ingestão.")
    pdfs = sorted(Path(settings.raw_data_directory).glob("*.pdf"))
    if not pdfs:
        raise SystemExit(f"Nenhum PDF em {settings.raw_data_directory}.")

    trechos = [trecho for pdf in pdfs for trecho in extrair_trechos(pdf)]
    vetores = embed_textos([t["texto"] for t in trechos], "RETRIEVAL_DOCUMENT")
    colecao = reset_collection()
    colecao.add(
        ids=[_id_trecho(t) for t in trechos],
        documents=[t["texto"] for t in trechos],
        embeddings=vetores,
        metadatas=[{k: t[k] for k in ("documento", "capitulo", "artigo", "pagina")} for t in trechos],
    )
    for pdf in pdfs:
        print(f"{pdf.name}: {sum(t['documento'] == pdf.name for t in trechos)} trechos")
    print(f"Total indexado: {colecao.count()} trechos em {settings.chroma_persist_directory}")


if __name__ == "__main__":
    main()
