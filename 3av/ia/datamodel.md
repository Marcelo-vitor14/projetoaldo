# DataModel.md — Mapeamento de Entidades e Contratos de Dados

## 1. Entidade: DocumentoChunk (Trecho do Regimento)
**Finalidade:** Representa uma unidade segmentada de texto extraída do Regimento Académico ou Manual do Aluno, contendo o conteúdo textual, os seus vetores de embedding e os metadados de localização original para fundamentação do RAG.

### Configuração do Banco Vetorial (ChromaDB)
- **Nome da Coleção:** `regimento_academico`
- **Modelo de Embedding:** Google `gemini-embedding-001` (substitui o `text-embedding-004`, descontinuado)
- **Dimensão dos Vetores:** 768 dimensões
- **Mapeamento de Persistência:** Diretório local `/app/data/vectordb`

### Campos e Atributos
- **`id`** (`str`, Obrigatório): Identificador único e estável do trecho. Gerado via hash SHA-256 do conteúdo e localização (ex: `doc_reg_art45_p12`).
- **`conteudo_texto`** (`str`, Obrigatório): Texto bruto extraído do trecho do PDF (entre 200 e 1000 caracteres).
- **`documento_fonte`** (`str`, Obrigatório): Nome do ficheiro de origem (ex: `Regimento_Academico_2026.pdf`).
- **`capitulo`** (`str`, Opcional): Nome ou título do capítulo correspondente.
- **`artigo`** (`str`, Obrigatório): Número do artigo ou secção identificada via Regex (ex: `Art. 45` ou `Secção II`).
- **`pagina`** (`int`, Obrigatório): Número da página original no ficheiro PDF ($\ge 1$).
- **`vetor_embedding`** (`list[float]`, Obrigatório): Lista de 768 números decimais representando o vetor semântico.

### Estados e Transições
- `NaoProcessado` $\rightarrow$ `Indexado` $\rightarrow$ `Obsoleto`
  - **Transição NaoProcessado $\rightarrow$ Indexado:** Ocorre quando o script `src/rag/ingest.py` realiza o chunking, gera os embeddings via API e persiste no ChromaDB.
  - **Transição Indexado $\rightarrow$ Obsoleto:** Ocorre ao carregar uma nova versão do PDF que substitui a coleção inteira.
  - **Rejeição:** Se o ficheiro PDF estiver corrompido, sem camada de OCR/texto ou com metadados de página ausentes.

---

## 2. Entidade: Mensagem e Contrato do Chat
**Finalidade:** Representa um turno individual de comunicação dentro do chat e define o schema Pydantic das requisições/respostas HTTP no FastAPI.

### Campos e Atributos (`Mensagem`)
- **`id`** (`str`, Obrigatório): Identificador UUID v4 gerado automaticamente.
- **`papel`** (`str`, Obrigatório): Emissor da mensagem. Valores aceites: `"user"`, `"assistant"`, `"system"`.
- **`conteudo`** (`str`, Obrigatório): Texto da mensagem enviada ou recebida.
- **`fontes_citadas`** (`list[FonteCitada]`, Opcional): Lista de referências RAG recuperadas. Preenchida apenas para o papel `"assistant"`.
- **`ferramenta_acionada`** (`str`, Opcional): Nome da Tool executada (`"calcular_nota_final"` ou `None`).
- **`timestamp`** (`datetime`, Obrigatório): Data e hora de registo em formato UTC/ISO-8601.

### Schema Pydantic do Contrato da API (`POST /api/v1/chat`)

```python
from pydantic import BaseModel, Field
from typing import Literal, Optional

class FonteCitada(BaseModel):
    documento: str
    artigo: str
    pagina: int
    trecho: str

class MensagemEntrada(BaseModel):
    role: Literal["user", "assistant"]
    content: str

class ChatRequest(BaseModel):
    session_id: str = Field(..., description="ID da sessão ativa mantida pela interface web")
    messages: list[MensagemEntrada] = Field(..., description="Histórico acumulado de mensagens da conversa")

class CalculoNoChat(BaseModel):
    p1: float
    p2: float
    media_parcial: float
    status: str                      # aprovado_direto | prova_final | reprovado_direto
    nota_necessaria_final: Optional[float] = None

class ChatResponse(BaseModel):
    session_id: str
    response: str
    sources: list[FonteCitada] = []
    tool_called: Optional[str] = None
    calculo: Optional[CalculoNoChat] = None   # preenchido quando a calculadora é acionada
    latency_ms: float
```