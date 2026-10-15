"""Contratos de entrada e saída da API."""

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator


class FonteCitada(BaseModel):
    documento: str
    artigo: str
    pagina: int = Field(ge=1)
    trecho: str


class MensagemEntrada(BaseModel):
    role: Literal["user", "assistant"]
    content: str = Field(min_length=1, max_length=8000)


class ChatRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    session_id: str = Field(min_length=1, max_length=128)
    messages: list[MensagemEntrada] = Field(min_length=1, max_length=50)

    @model_validator(mode="after")
    def validate_history(self):
        if self.messages[-1].role != "user":
            raise ValueError("A última mensagem deve ser do usuário.")
        if sum(len(message.content) for message in self.messages) > 20000:
            raise ValueError("O histórico excede o limite permitido.")
        return self


class CalculoRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    p1: float = Field(ge=0, le=10, allow_inf_nan=False)
    p2: float = Field(ge=0, le=10, allow_inf_nan=False)


class CalculoResponse(BaseModel):
    media_parcial: float
    status: str
    nota_necessaria_final: float | None = None


class ChatResponse(BaseModel):
    session_id: str
    response: str
    sources: list[FonteCitada] = Field(default_factory=list)
    tool_called: str | None = None
    latency_ms: float = Field(ge=0)
