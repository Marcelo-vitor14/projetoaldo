"""Aplicação FastAPI: chat com agente Gemini + RAG, e cálculo determinístico de notas."""

import asyncio
import json
import re
import secrets
import tempfile
import time
import uuid
from pathlib import Path

import structlog
from fastapi import FastAPI, File, Form, Request, UploadFile
from fastapi.responses import JSONResponse, Response, StreamingResponse
from pydantic import ValidationError
from starlette.exceptions import HTTPException as StarletteHTTPException
from fastapi.exceptions import RequestValidationError

from src.agent.core import AgenteIndisponivel, responder, responder_aos_poucos
from src.api.schemas import (
    CalculoRequest,
    CalculoResponse,
    ChatRequest,
    ChatResponse,
    FonteCitada,
    SituacaoDisciplina,
    SituacaoRequest,
    SituacaoResponse,
)
from src.config import get_settings
from src.rag.ingest import BaseOcupada, PdfSemTexto, SemVersaoAnterior, montar_base, verificar_pdf, voltar_base
from src.rag.vectorstore import estado_base, total_indexado
from src.tools.calculator import calcular_nota_final
from src.tools.situacao import calcular_situacao

app = FastAPI(
    title="Uni Search",
    description="Assistente informativo para normas acadêmicas e cálculo de notas.",
    version="0.1.0",
)
structlog.configure(processors=[structlog.processors.TimeStamper(fmt="iso"), structlog.processors.JSONRenderer()])
logger = structlog.get_logger()

@app.middleware("http")
async def exigir_token(request: Request, call_next):
    """Só o portal (que tem o token) chama as rotas /api/. Sem token configurado, recusa tudo."""
    if request.url.path.startswith("/api/"):
        esperado = get_settings().ia_token
        if not esperado:
            return JSONResponse(status_code=503, content={"detail": "Token da API não configurado."})
        if not secrets.compare_digest(request.headers.get("x-ia-token", ""), esperado):
            return JSONResponse(status_code=401, content={"detail": "Token inválido."})
    return await call_next(request)


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
        resultado = await asyncio.to_thread(responder, [m.model_dump() for m in request.messages], request.aluno)
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
        dados_aluno=resultado.dados_aluno,
        latency_ms=(time.perf_counter() - started) * 1000,
    )


@app.post("/api/v1/chat/aos-poucos")
async def chat_aos_poucos(request: ChatRequest):
    """Como /api/v1/chat, mas a resposta vem em pedaços, uma linha JSON por vez (NDJSON):
    {"tipo": "texto", "texto": "..."} enquanto o Gemini escreve, e no fim {"tipo": "fim", ...} com
    as fontes, o cálculo e os dados do aluno (ou {"tipo": "erro", "detail": "..."})."""
    started = time.perf_counter()
    if not get_settings().gemini_api_key:
        r = _responder_por_regex(request, started)
        corpo = [{"tipo": "texto", "texto": r.response}, {"tipo": "fim", **r.model_dump(exclude={"session_id", "response", "latency_ms"})}]
        return StreamingResponse((json.dumps(e, ensure_ascii=False) + "\n" for e in corpo), media_type="application/x-ndjson")

    gerador = responder_aos_poucos([m.model_dump() for m in request.messages], request.aluno)
    fim = object()

    async def linhas():
        try:
            while True:
                evento = await asyncio.to_thread(next, gerador, fim)  # o Gemini roda fora do laço do servidor
                if evento is fim:
                    return
                if evento["tipo"] == "texto":
                    yield json.dumps(evento, ensure_ascii=False) + "\n"
                else:
                    r = evento["resposta"]
                    logger.info("agent_answered", tool_called=r.ferramenta, rag_chunks_retrieved=len(r.fontes), streaming=True)
                    final = ChatResponse(session_id=request.session_id, response=r.texto, sources=[FonteCitada(**f) for f in r.fontes],
                                         tool_called=r.ferramenta, calculo=r.calculo, dados_aluno=r.dados_aluno,
                                         latency_ms=(time.perf_counter() - started) * 1000)
                    yield json.dumps({"tipo": "fim", **final.model_dump(exclude={"session_id", "latency_ms"})}, ensure_ascii=False) + "\n"
        except AgenteIndisponivel as exc:
            logger.warning("agent_unavailable", reason=str(exc), streaming=True)
            yield json.dumps({"tipo": "erro", "detail": "O assistente está temporariamente indisponível. Tente novamente em instantes."}) + "\n"

    return StreamingResponse(linhas(), media_type="application/x-ndjson")


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
            resultado = calcular_nota_final([p1, p2])
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
    resultado = calcular_nota_final(request.notas)
    logger.info("tool_executed", tool_called="calcular_nota_final")
    return CalculoResponse(
        media_parcial=resultado.media_parcial,
        status=resultado.status,
        nota_necessaria_final=resultado.nota_necessaria_final,
    )


@app.post("/api/v1/situacao-academica", response_model=SituacaoResponse)
async def situacao_academica(request: SituacaoRequest) -> SituacaoResponse:
    """Situação de várias disciplinas de uma vez (usada pelo perfil do aluno no portal)."""
    disciplinas = []
    for d in request.disciplinas:
        r = calcular_situacao(d.carga_horaria, d.faltas, d.notas, d.total_avaliacoes, d.prova_final)
        disciplinas.append(SituacaoDisciplina(codigo=d.codigo, **r.model_dump()))
    logger.info("tool_executed", tool_called="calcular_situacao", disciplinas=len(disciplinas))
    return SituacaoResponse(disciplinas=disciplinas)


TAMANHO_MAXIMO_PDF = 20 * 1024 * 1024  # o mesmo limite do portal


async def _salvar_pdf(arquivo: UploadFile, pasta: Path, nome: str) -> Path | JSONResponse:
    conteudo = await arquivo.read(TAMANHO_MAXIMO_PDF + 1)
    if len(conteudo) > TAMANHO_MAXIMO_PDF:
        return JSONResponse(status_code=413, content={"detail": f"{nome}: o PDF passa de 20 MB."})
    if not conteudo.startswith(b"%PDF"):
        return JSONResponse(status_code=422, content={"detail": f"{nome}: o arquivo não é um PDF."})
    caminho = pasta / f"{uuid.uuid4().hex}.pdf"
    caminho.write_bytes(conteudo)
    return caminho


def _erro_de_pdf(exc: Exception) -> bool:
    return isinstance(exc, PdfSemTexto) or exc.__class__.__module__.startswith("pypdf")


@app.post("/api/v1/documentos/verificar")
async def verificar_documento(arquivo: UploadFile = File(...), nome: str = Form(min_length=1, max_length=120)):
    """Confere o PDF logo no envio, sem gerar embeddings: diz em quantos trechos ele vai virar."""
    with tempfile.TemporaryDirectory() as pasta:
        caminho = await _salvar_pdf(arquivo, Path(pasta), nome)
        if isinstance(caminho, JSONResponse):
            return caminho
        try:
            return await asyncio.to_thread(verificar_pdf, caminho, nome)
        except Exception as exc:
            if _erro_de_pdf(exc):
                motivo = str(exc) if isinstance(exc, PdfSemTexto) else "não foi possível ler o PDF (arquivo corrompido?)."
                return JSONResponse(status_code=422, content={"detail": motivo})
            raise


@app.get("/api/v1/base")
async def ver_base():
    """Versão ativa da base, a anterior guardada e quantos trechos a ativa tem."""
    return {**estado_base(), "trechos": total_indexado()}


@app.post("/api/v1/base")
async def publicar_base(
    versao: int = Form(ge=2, le=100000),
    documentos: str = Form(..., description='JSON: [{"documento_id": "...", "nome": "..."}], na ordem dos arquivos'),
    arquivos: list[UploadFile] = File(...),
):
    """Monta a base inteira do zero com estes documentos e só troca a ativa se tudo der certo."""
    try:
        lista = [(str(d["documento_id"]), str(d["nome"])) for d in json.loads(documentos)]
    except (ValueError, KeyError, TypeError):
        return JSONResponse(status_code=422, content={"detail": "Lista de documentos inválida."})
    if not lista or len(lista) != len(arquivos):
        return JSONResponse(status_code=422, content={"detail": "Cada documento precisa do seu arquivo (e pelo menos um)."})

    with tempfile.TemporaryDirectory() as pasta:
        entrada = []
        for (documento_id, nome), arquivo in zip(lista, arquivos):
            caminho = await _salvar_pdf(arquivo, Path(pasta), nome)
            if isinstance(caminho, JSONResponse):
                return caminho
            entrada.append((caminho, documento_id, nome))
        try:
            resultado = await asyncio.to_thread(montar_base, entrada, versao)
        except BaseOcupada as exc:
            return JSONResponse(status_code=409, content={"detail": str(exc)})
        except ValueError as exc:  # inclui PdfSemTexto: o motivo já diz qual documento
            return JSONResponse(status_code=422, content={"detail": str(exc)})
        except Exception as exc:
            if _erro_de_pdf(exc):
                return JSONResponse(status_code=422, content={"detail": "Um dos PDFs não pôde ser lido (arquivo corrompido?)."})
            logger.warning("base_failed", versao=versao, erro=exc.__class__.__name__)
            return JSONResponse(status_code=503, content={"detail": "Não foi possível gerar os embeddings agora. A base atual continua valendo."})
    logger.info("base_published", versao=versao, trechos=resultado["trechos"], documentos=len(lista))
    return resultado


@app.post("/api/v1/base/voltar")
async def voltar_para_anterior():
    """Desfaz a última publicação: a versão anterior volta a ser a ativa, na hora."""
    try:
        estado = await asyncio.to_thread(voltar_base)
    except (SemVersaoAnterior, BaseOcupada) as exc:
        return JSONResponse(status_code=409, content={"detail": str(exc)})
    logger.info("base_reverted", versao=estado["versao"])
    return {**estado, "trechos": total_indexado()}
