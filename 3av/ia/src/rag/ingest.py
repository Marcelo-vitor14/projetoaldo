"""Ingestão: corta o PDF por artigo ou seção (ou, sem artigos, por parágrafos) e grava no ChromaDB.

Toda publicação monta a base inteira do zero, numa versão nova, e só troca quando ela está completa:
- pela API (/api/v1/base), quando a secretaria clica em "Atualizar a base do assistente" no portal;
- pelo terminal (python -m src.rag.ingest), com os PDFs de data/raw.
"""

import hashlib
import re
import threading
from pathlib import Path

from pypdf import PdfReader

from src.config import get_settings
from src.rag.vectorstore import apagar_colecao, criar_colecao, embed_textos, estado_base, salvar_estado

# Início de um trecho: "Art. 15." ou "Art. 1º" no Regimento; "Seção 6 — Biblioteca" no Manual.
_INICIO_RE = re.compile(r"^(Art\.\s*\d+[º°]?|Seção\s+\d+)")
_CAPITULO_RE = re.compile(r"^CAP[IÍ]TULO\s+[IVXLC]+", re.IGNORECASE)
# Cabeçalho e rodapé repetidos em toda página.
_RUIDO_RE = re.compile(r"^DOCUMENTO FICTÍCIO|página \d+$")


class PdfSemTexto(ValueError):
    """O PDF não tem texto extraível (provavelmente escaneado, só imagem)."""


# Sem artigos: blocos de parágrafos de até este tamanho, sem atravessar páginas
_TAMANHO_BLOCO = 1200


def _por_paragrafos(paginas: list[str], documento: str) -> list[dict]:
    """Corte para PDFs sem artigos (editais, comunicados): a citação fica 'Documento, p. Y'."""
    trechos = []
    for numero_pagina, texto in enumerate(paginas, start=1):
        bloco = ""
        linhas = [l.strip() for l in texto.splitlines() if l.strip() and not _RUIDO_RE.search(l.strip())]
        for linha in linhas:
            if bloco and len(bloco) + len(linha) > _TAMANHO_BLOCO:
                trechos.append({"documento": documento, "capitulo": "", "artigo": documento, "pagina": numero_pagina, "texto": bloco})
                bloco = ""
            bloco = f"{bloco} {linha}".strip()
        if bloco:
            trechos.append({"documento": documento, "capitulo": "", "artigo": documento, "pagina": numero_pagina, "texto": bloco})
    return trechos


def extrair_trechos(pdf: Path, documento: str | None = None) -> list[dict]:
    """Devolve um trecho por artigo/seção, com documento, capítulo, artigo, página e texto.

    `documento` é o nome que aparece na citação (padrão: o nome do arquivo). Sem artigos ou seções
    numerados, corta por parágrafos; sem texto nenhum, levanta PdfSemTexto.
    """
    documento = documento or pdf.name
    paginas = [(pagina.extract_text() or "") for pagina in PdfReader(pdf).pages]
    if not any(p.strip() for p in paginas):
        raise PdfSemTexto("O PDF não tem texto (parece escaneado). Envie a versão com texto.")

    trechos: list[dict] = []
    atual: dict | None = None
    capitulo = ""
    for numero_pagina, pagina in enumerate(paginas, start=1):
        for linha in pagina.splitlines():
            linha = linha.strip()
            if not linha or _RUIDO_RE.search(linha):
                continue
            if _CAPITULO_RE.match(linha):
                capitulo = linha
                continue
            inicio = _INICIO_RE.match(linha)
            if inicio:
                atual = {
                    "documento": documento,
                    "capitulo": capitulo,
                    "artigo": re.sub(r"\s+", " ", inicio.group(1)),
                    "pagina": numero_pagina,
                    "texto": linha,
                }
                trechos.append(atual)
            elif atual:
                atual["texto"] += " " + linha
    return trechos or _por_paragrafos(paginas, documento)


def _id_trecho(documento_id: str, trecho: dict, ordem: int) -> str:
    chave = f"{documento_id}|{trecho['artigo']}|{trecho['pagina']}|{ordem}"
    return hashlib.sha256(chave.encode()).hexdigest()[:16]


def _gravar(colecao, documento_id: str, trechos: list[dict], vetores: list[list[float]]) -> None:
    colecao.add(
        ids=[_id_trecho(documento_id, t, i) for i, t in enumerate(trechos)],
        documents=[t["texto"] for t in trechos],
        embeddings=vetores,
        metadatas=[{"documento_id": documento_id, **{k: t[k] for k in ("documento", "capitulo", "artigo", "pagina")}} for t in trechos],
    )


class BaseOcupada(RuntimeError):
    """Já existe uma base sendo montada: só uma por vez."""


class SemVersaoAnterior(RuntimeError):
    """Não há versão anterior guardada para voltar."""


_trava = threading.Lock()


def verificar_pdf(pdf: Path, nome: str) -> dict:
    """Confere o PDF sem gerar embeddings (rápido e sem gastar cota): quantos trechos ele vai virar."""
    trechos = extrair_trechos(pdf, nome)
    return {"trechos": len(trechos), "com_artigos": any(t["artigo"] != nome for t in trechos)}


def montar_base(documentos: list[tuple[Path, str, str]], versao: int) -> dict:
    """Monta uma base NOVA e inteira com estes documentos [(pdf, documento_id, nome)] e só então a ativa.

    Se qualquer passo falhar (PDF ruim, Gemini fora do ar), a base ativa continua exatamente como estava.
    A ativa de antes vira a "anterior" (para poder voltar); a anterior de antes é apagada.
    """
    if not documentos:
        raise ValueError("A base precisa de pelo menos um documento.")
    if not _trava.acquire(blocking=False):
        raise BaseOcupada("Já existe uma base sendo montada.")
    nova = f"base_v{versao}"
    try:
        extraidos = []
        for pdf, documento_id, nome in documentos:
            try:
                extraidos.append((documento_id, extrair_trechos(pdf, nome)))
            except PdfSemTexto as exc:
                raise PdfSemTexto(f"{nome}: {exc}") from exc
        todos = [t for _, trechos in extraidos for t in trechos]
        vetores = embed_textos([t["texto"] for t in todos], "RETRIEVAL_DOCUMENT")  # tudo antes de tocar na base

        estado = estado_base()
        if nova in (estado["ativa"], estado["anterior"]):
            raise ValueError(f"A versão {versao} já existe.")
        colecao = criar_colecao(nova)
        inicio = 0
        for documento_id, trechos in extraidos:
            _gravar(colecao, documento_id, trechos, vetores[inicio : inicio + len(trechos)])
            inicio += len(trechos)

        salvar_estado({"ativa": nova, "versao": versao, "anterior": estado["ativa"], "versao_anterior": estado["versao"]})
        if estado["anterior"]:
            apagar_colecao(estado["anterior"])  # guarda só uma versão anterior
        return {
            "versao": versao,
            "trechos": len(todos),
            "documentos": [{"documento_id": d, "trechos": len(t)} for d, t in extraidos],
        }
    except Exception:
        if estado_base()["ativa"] != nova:
            apagar_colecao(nova)  # montagem pela metade não fica
        raise
    finally:
        _trava.release()


def voltar_base() -> dict:
    """Volta para a versão anterior (sem reprocessar nada) e descarta a que estava ativa."""
    with _trava:
        estado = estado_base()
        if not estado["anterior"]:
            raise SemVersaoAnterior("Não há versão anterior guardada.")
        salvar_estado({"ativa": estado["anterior"], "versao": estado["versao_anterior"], "anterior": None, "versao_anterior": None})
        apagar_colecao(estado["ativa"])
        return estado_base()


def main() -> None:
    """Pelo terminal: monta uma versão nova com todos os PDFs de data/raw (documento_id = nome do arquivo)."""
    settings = get_settings()
    if not settings.gemini_api_key:
        raise SystemExit("Defina GEMINI_API_KEY no .env antes da ingestão.")
    pdfs = sorted(Path(settings.raw_data_directory).glob("*.pdf"))
    if not pdfs:
        raise SystemExit(f"Nenhum PDF em {settings.raw_data_directory}.")

    resultado = montar_base([(pdf, pdf.name, pdf.name) for pdf in pdfs], estado_base()["versao"] + 1)
    for d in resultado["documentos"]:
        print(f"{d['documento_id']}: {d['trechos']} trechos")
    print(f"Versão {resultado['versao']} ativa: {resultado['trechos']} trechos em {settings.chroma_persist_directory}")


if __name__ == "__main__":
    main()
