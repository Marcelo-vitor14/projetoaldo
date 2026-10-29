"""Integração com o ChromaDB persistente e com os embeddings do Gemini."""

from pathlib import Path

import chromadb
from chromadb.config import Settings as ChromaSettings
from google import genai
from google.genai import types

from src.config import get_settings

COLECAO = "regimento_academico"
DIMENSOES = 768


def _cliente_chroma() -> chromadb.ClientAPI:
    return chromadb.PersistentClient(
        path=get_settings().chroma_persist_directory,
        settings=ChromaSettings(anonymized_telemetry=False),
    )


def get_collection() -> chromadb.Collection:
    return _cliente_chroma().get_or_create_collection(COLECAO, metadata={"hnsw:space": "cosine"})


def reset_collection() -> chromadb.Collection:
    cliente = _cliente_chroma()
    if COLECAO in [c.name for c in cliente.list_collections()]:
        cliente.delete_collection(COLECAO)
    return cliente.create_collection(COLECAO, metadata={"hnsw:space": "cosine"})


def total_indexado() -> int:
    """Quantidade de trechos indexados; 0 se a base ainda não foi criada."""
    if not Path(get_settings().chroma_persist_directory).exists():
        return 0
    return get_collection().count()


def embed_textos(textos: list[str], tarefa: str) -> list[list[float]]:
    """Gera embeddings; tarefa é RETRIEVAL_DOCUMENT (trechos) ou RETRIEVAL_QUERY (pergunta)."""
    settings = get_settings()
    cliente = genai.Client(api_key=settings.gemini_api_key)
    vetores: list[list[float]] = []
    for inicio in range(0, len(textos), 50):
        resposta = cliente.models.embed_content(
            model=settings.gemini_embedding_model,
            contents=textos[inicio : inicio + 50],
            config=types.EmbedContentConfig(task_type=tarefa, output_dimensionality=DIMENSOES),
        )
        vetores.extend(embedding.values for embedding in resposta.embeddings)
    return vetores
