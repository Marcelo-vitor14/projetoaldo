"""Contratos de entrada e saída da API."""

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

from src.tools.calculator import Nota
from src.tools.dados_aluno import ResumoAluno


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
    # Dados do próprio aluno, enviados pelo portal (sem nome, matrícula ou e-mail)
    aluno: ResumoAluno | None = None

    @model_validator(mode="after")
    def validate_history(self):
        if self.messages[-1].role != "user":
            raise ValueError("A última mensagem deve ser do usuário.")
        if sum(len(message.content) for message in self.messages) > 20000:
            raise ValueError("O histórico excede o limite permitido.")
        return self


class CalculoRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    notas: list[Nota] = Field(min_length=1, max_length=10)


class CalculoResponse(BaseModel):
    media_parcial: float
    status: str
    nota_necessaria_final: float | None = None


class DisciplinaEntrada(BaseModel):
    model_config = ConfigDict(extra="forbid")

    codigo: str = Field(min_length=1, max_length=20)
    carga_horaria: int = Field(gt=0, le=400)
    faltas: int = Field(ge=0, le=400)
    total_avaliacoes: int = Field(default=2, ge=1, le=10)
    notas: list[Nota] = Field(default_factory=list, max_length=10)  # as já lançadas
    prova_final: float | None = Field(default=None, ge=0, le=10, allow_inf_nan=False)

    @model_validator(mode="after")
    def notas_cabem_nas_avaliacoes(self):
        if len(self.notas) > self.total_avaliacoes:
            raise ValueError("Há mais notas do que avaliações na disciplina.")
        return self


class SituacaoRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    disciplinas: list[DisciplinaEntrada] = Field(min_length=1, max_length=20)


class SituacaoDisciplina(BaseModel):
    codigo: str
    situacao: str
    media_parcial: float | None = None
    nota_necessaria_final: float | None = None
    media_final: float | None = None
    limite_faltas: int
    faltas_restantes: int


class SituacaoResponse(BaseModel):
    disciplinas: list[SituacaoDisciplina]


class CalculoNoChat(CalculoResponse):
    """Resultado da calculadora quando o agente a aciona, para a interface exibir o cartão."""

    notas: list[float]


class DadoConsultado(BaseModel):
    """Disciplina do aluno usada na resposta (o cartão 'Consultei seus dados' no portal)."""

    disciplina: str
    codigo: str
    semestre: str
    notas: list[float]
    faltas: int
    situacao: str
    media_parcial: float | None = None
    nota_necessaria_final: float | None = None
    limite_faltas: int | None = None  # só nas disciplinas em curso


class ChatResponse(BaseModel):
    session_id: str
    response: str
    sources: list[FonteCitada] = Field(default_factory=list)
    tool_called: str | None = None
    calculo: CalculoNoChat | None = None
    dados_aluno: list[DadoConsultado] = Field(default_factory=list)
    latency_ms: float = Field(ge=0)
