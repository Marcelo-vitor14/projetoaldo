"""Aplicação FastAPI: chat com agente Gemini + RAG, e cálculo determinístico de notas."""

import asyncio
import re
import time
import uuid
import os
from pathlib import Path

import structlog
from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, JSONResponse, Response
from fastapi.middleware.cors import CORSMiddleware
from pydantic import ValidationError
from starlette.exceptions import HTTPException as StarletteHTTPException
from fastapi.exceptions import RequestValidationError

from src.agent.core import AgenteIndisponivel, responder
from src.api.schemas import CalculoRequest, CalculoResponse, ChatRequest, ChatResponse, FonteCitada
from src.config import get_settings
from src.rag.vectorstore import total_indexado
from src.tools.calculator import calcular_nota_final

app = FastAPI(
    title="Tira-Dúvidas Acadêmico",
    description="Assistente informativo para normas acadêmicas e cálculo de notas.",
    version="0.1.0",
)
structlog.configure(processors=[structlog.processors.TimeStamper(fmt="iso"), structlog.processors.JSONRenderer()])
logger = structlog.get_logger()

allowed_origins = [
    origin.strip()
    for origin in os.getenv("CORS_ORIGINS", "http://localhost:8000").split(",")
    if origin.strip()
]
app.add_middleware(
    CORSMiddleware,
    allow_origins=allowed_origins,
    allow_credentials=False,
    allow_methods=["GET", "POST"],
    allow_headers=["Content-Type", "X-Request-ID"],
)


@app.middleware("http")
async def request_logging(request: Request, call_next):
    incoming_id = request.headers.get("x-request-id", "")
    request_id = incoming_id if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", incoming_id) else str(uuid.uuid4())
    started = time.perf_counter()
    response = await call_next(request)
    latency_ms = (time.perf_counter() - started) * 1000
    response.headers["x-request-id"] = request_id
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["X-Frame-Options"] = "DENY"
    response.headers["Referrer-Policy"] = "no-referrer"
    logger.info(
        "http_request",
        request_id=request_id,
        method=request.method,
        path=request.url.path,
        status_code=response.status_code,
        latency_ms=round(latency_ms, 2),
    )
    return response


@app.exception_handler(RequestValidationError)
async def validation_error_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    # Não devolve o valor inválido enviado pelo cliente no corpo da resposta.
    fields = [".".join(str(part) for part in error["loc"]) for error in exc.errors()]
    return JSONResponse(status_code=422, content={"detail": "Requisição inválida.", "fields": fields})


@app.exception_handler(StarletteHTTPException)
async def http_error_handler(request: Request, exc: StarletteHTTPException) -> JSONResponse:
    return JSONResponse(status_code=exc.status_code, content={"detail": "Não foi possível processar a requisição."})


@app.exception_handler(Exception)
async def unexpected_error_handler(request: Request, exc: Exception) -> JSONResponse:
    logger.error("unhandled_error", request_id=request.headers.get("x-request-id", "unknown"))
    return JSONResponse(status_code=500, content={"detail": "Ocorreu um erro interno. Tente novamente."})

_NUMERO = r"(-?\d+(?:[.,]\d+)?)"
_NOTAS_RE = (
    re.compile(rf"\bp1\s*(?:=|:)?\s*{_NUMERO}\D+?\bp2\s*(?:=|:)?\s*{_NUMERO}", re.IGNORECASE),
    re.compile(rf"{_NUMERO}\s*(?:na\s*)?\bp1\D+?{_NUMERO}\s*(?:na\s*)?\bp2", re.IGNORECASE),
)


def _formatar_nota(valor: float) -> str:
    return f"{valor:.2f}".rstrip("0").rstrip(".")


WEB_INDEX = Path(__file__).resolve().parent.parent / "web" / "index.html"


@app.get("/", include_in_schema=False)
async def pagina_inicial() -> FileResponse:
    """Interface web do chat (HTML/CSS/JS sem dependências), servida pela própria API."""
    return FileResponse(WEB_INDEX, media_type="text/html")


@app.get("/health/liveness")
async def liveness() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/health/readiness")
async def readiness() -> Response:
    # Pronto apenas com o ChromaDB acessível e com trechos indexados.
    try:
        indexados = await asyncio.to_thread(total_indexado)
    except Exception:
        indexados = 0
    if indexados == 0:
        return JSONResponse(
            status_code=503,
            content={"status": "not_ready", "vector_store": "not_configured"},
        )
    return JSONResponse(status_code=200, content={"status": "ready", "chunks": indexados})


@app.post("/api/v1/chat", response_model=ChatResponse)
async def chat(request: ChatRequest) -> ChatResponse:
    started = time.perf_counter()
    if request.messages[-1].role != "user":
        return JSONResponse(status_code=422, content={"detail": "A última mensagem deve ser do usuário."})
    if not get_settings().gemini_api_key:
        return _responder_por_regex(request, started)

    try:
        resultado = await asyncio.to_thread(responder, [m.model_dump() for m in request.messages])
    except AgenteIndisponivel as exc:
        logger.warning("agent_unavailable", reason=str(exc))
        return JSONResponse(
            status_code=503,
            content={"detail": "O assistente está temporariamente indisponível. Tente novamente em instantes."},
        )
    logger.info("agent_answered", tool_called=resultado.ferramenta, rag_chunks_retrieved=len(resultado.fontes))
    return ChatResponse(
        session_id=request.session_id,
        response=resultado.texto,
        sources=[FonteCitada(**fonte) for fonte in resultado.fontes],
        tool_called=resultado.ferramenta,
        calculo=resultado.calculo,
        latency_ms=(time.perf_counter() - started) * 1000,
    )


def _responder_por_regex(request: ChatRequest, started: float) -> ChatResponse:
    """Plano B sem Gemini: reconhece P1 e P2 por expressão regular."""
    latest = request.messages[-1].content
    match = None
    for pattern in _NOTAS_RE:
        match = pattern.search(latest)
        if match:
            break

    if match:
        p1, p2 = (float(value.replace(",", ".")) for value in match.groups())
        try:
            resultado = calcular_nota_final(p1, p2)
        except ValidationError:
            answer = "As notas precisam estar entre 0 e 10. Confira os valores de P1 e P2 e tente novamente."
            elapsed = (time.perf_counter() - started) * 1000
            return ChatResponse(session_id=request.session_id, response=answer, latency_ms=elapsed)
        if resultado.status == "aprovado_direto":
            answer = f"Sua média parcial é {_formatar_nota(resultado.media_parcial)}: aprovação direta."
        elif resultado.status == "prova_final":
            answer = (
                f"Sua média parcial é {_formatar_nota(resultado.media_parcial)}. "
                f"Você precisa de {_formatar_nota(resultado.nota_necessaria_final)} na Prova Final."
            )
        else:
            answer = f"Sua média parcial é {_formatar_nota(resultado.media_parcial)}: reprovação direta."
        tool_called = "calcular_nota_final"
        calculo = resultado.model_dump()
    else:
        answer = (
            "Ainda não encontrei essa norma nos documentos oficiais. "
            "A consulta ao Regimento e ao Manual será ativada quando a base documental estiver configurada."
        )
        tool_called = None
        calculo = None

    elapsed = (time.perf_counter() - started) * 1000
    return ChatResponse(
        session_id=request.session_id,
        response=answer,
        tool_called=tool_called,
        calculo=calculo,
        latency_ms=elapsed,
    )


@app.post("/api/v1/calcular-nota", response_model=CalculoResponse)
async def calcular_nota(request: CalculoRequest) -> CalculoResponse:
    resultado = calcular_nota_final(request.p1, request.p2)
    logger.info("tool_executed", tool_called="calcular_nota_final")
    return CalculoResponse(
        media_parcial=resultado.media_parcial,
        status=resultado.status,
        nota_necessaria_final=resultado.nota_necessaria_final,
    )
