"""Busca vetorial e ingestão, com vetores falsos e um ChromaDB em memória."""

import uuid

import chromadb

import src.rag.retriever as retriever
from src.rag.ingest import _id_trecho


def test_busca_descarta_trechos_distantes(monkeypatch):
    colecao = chromadb.EphemeralClient().create_collection(f"teste-{uuid.uuid4().hex}", metadata={"hnsw:space": "cosine"})
    colecao.add(
        ids=["a", "b"],
        documents=["Art. 15. Limite de faltas.", "Seção 6 — Biblioteca."],
        embeddings=[[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]],
        metadatas=[
            {"documento": "Regimento.pdf", "artigo": "Art. 15", "pagina": 2},
            {"documento": "Manual.pdf", "artigo": "Seção 6", "pagina": 2},
        ],
    )
    monkeypatch.setattr(retriever, "get_collection", lambda: colecao)
    monkeypatch.setattr(retriever, "embed_textos", lambda textos, tarefa: [[1.0, 0.05, 0.0]])

    trechos = retriever.buscar("quantas faltas posso ter?", quantidade=2)

    assert [t["artigo"] for t in trechos] == ["Art. 15"]
    assert trechos[0]["pagina"] == 2
    assert trechos[0]["distancia"] < retriever.DISTANCIA_MAXIMA


def test_id_do_trecho_e_estavel_e_unico_por_artigo():
    art15 = {"documento": "R.pdf", "artigo": "Art. 15", "pagina": 2}
    art16 = {"documento": "R.pdf", "artigo": "Art. 16", "pagina": 2}

    assert _id_trecho("R.pdf", art15, 0) == _id_trecho("R.pdf", dict(art15), 0)
    assert _id_trecho("R.pdf", art15, 0) != _id_trecho("R.pdf", art16, 1)
    assert _id_trecho("R.pdf", art15, 0) != _id_trecho("outro-doc", art15, 0)  # mesmo artigo, documento diferente
    assert len(_id_trecho("R.pdf", art15, 0)) == 16
