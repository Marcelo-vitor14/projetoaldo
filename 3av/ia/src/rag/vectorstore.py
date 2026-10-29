"""Integração com o ChromaDB persistente e com os embeddings do Gemini.

A base é versionada: cada publicação da secretaria monta uma coleção nova inteira (base_v5, base_v6...)
e só então ela vira a ativa. A anterior fica guardada para poder voltar. Qual é a ativa fica em
base_ativa.json, ao lado do banco; sem esse arquivo, a ativa é a coleção original do MVP (versão 1).
"""

import json
from pathlib import Path

import chromadb
from chromadb.config import Settings as ChromaSettings
from google import genai
from google.genai import types

from src.config import get_settings

COLECAO = "regimento_academico"  # coleção original (versão 1, criada pela ingestão do MVP)
DIMENSOES = 768
PONTEIRO = "base_ativa.json"


def _cliente_chroma() -> chromadb.ClientAPI:
    return chromadb.PersistentClient(
        path=get_settings().chroma_persist_directory,
        settings=ChromaSettings(anonymized_telemetry=False),
    )


def _ponteiro() -> Path:
    return Path(get_settings().chroma_persist_directory) / PONTEIRO


def estado_base() -> dict:
    """{"ativa": coleção, "versao": n, "anterior": coleção ou None, "versao_anterior": n ou None}."""
    if _ponteiro().exists():
        return json.loads(_ponteiro().read_text())
    return {"ativa": COLECAO, "versao": 1, "anterior": None, "versao_anterior": None}


def salvar_estado(estado: dict) -> None:
    """Troca a base ativa de uma vez (grava num arquivo temporário e renomeia)."""
    _ponteiro().parent.mkdir(parents=True, exist_ok=True)
    tmp = _ponteiro().with_suffix(".tmp")
    tmp.write_text(json.dumps(estado))
    tmp.replace(_ponteiro())


def get_collection() -> chromadb.Collection:
    """A base ativa: é a que a busca do chat usa."""
    return _cliente_chroma().get_or_create_collection(estado_base()["ativa"], metadata={"hnsw:space": "cosine"})


def criar_colecao(nome: str) -> chromadb.Collection:
    apagar_colecao(nome)
    return _cliente_chroma().create_collection(nome, metadata={"hnsw:space": "cosine"})


def apagar_colecao(nome: str) -> None:
    cliente = _cliente_chroma()
    if nome in [c.name for c in cliente.list_collections()]:
        cliente.delete_collection(nome)


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
