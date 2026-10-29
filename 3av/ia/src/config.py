"""Configurações lidas do ambiente e do arquivo .env."""

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    gemini_api_key: str = ""
    gemini_model: str = "gemini-flash-lite-latest"
    # Quanto o modelo "pensa" antes de responder (só nos Gemini 3.x): minimal, low, medium ou high.
    # Baixo é o certo para dúvidas rápidas: as contas são do código, não da IA.
    gemini_thinking: str = "low"
    # Se o principal estourar o limite por minuto (erro 429), usa este por 1 minuto. Vazio = sem reserva.
    gemini_model_reserva: str = "gemini-flash-lite-latest"
    gemini_embedding_model: str = "gemini-embedding-001"
    chroma_persist_directory: str = "data/vectordb"
    raw_data_directory: str = "data/raw"
    # Segredo compartilhado com o portal: sem ele, nenhuma rota /api/ responde
    ia_token: str = ""


@lru_cache
def get_settings() -> Settings:
    return Settings()
