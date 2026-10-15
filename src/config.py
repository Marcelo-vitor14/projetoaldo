"""Configurações lidas do ambiente e do arquivo .env."""

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    gemini_api_key: str = ""
    gemini_model: str = "gemini-flash-lite-latest"
    gemini_embedding_model: str = "gemini-embedding-001"
    chroma_persist_directory: str = "data/vectordb"
    raw_data_directory: str = "data/raw"


@lru_cache
def get_settings() -> Settings:
    return Settings()
