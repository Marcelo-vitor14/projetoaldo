"""Busca vetorial dos trechos do Regimento e do Manual."""

from src.rag.vectorstore import embed_textos, get_collection

# Distância de cosseno máxima para considerar um trecho relevante (0 = idêntico).
# Calibrada com os PDFs de teste: acertos ficam abaixo de 0,30; fora do assunto, acima de 0,43.
DISTANCIA_MAXIMA = 0.35


def buscar(pergunta: str, quantidade: int = 4) -> list[dict]:
    """Devolve os trechos mais próximos da pergunta, já com os metadados de citação."""
    vetor = embed_textos([pergunta], "RETRIEVAL_QUERY")[0]
    resultado = get_collection().query(query_embeddings=[vetor], n_results=quantidade)
    trechos = []
    for texto, meta, distancia in zip(
        resultado["documents"][0], resultado["metadatas"][0], resultado["distances"][0]
    ):
        if distancia <= DISTANCIA_MAXIMA:
            trechos.append(
                {
                    "documento": meta["documento"],
                    "artigo": meta["artigo"],
                    "pagina": int(meta["pagina"]),
                    "trecho": texto,
                    "distancia": round(distancia, 3),
                }
            )
    return trechos
