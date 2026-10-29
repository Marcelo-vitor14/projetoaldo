# Plan.md — Plano de Arquitetura e Engenharia Técnica

## 1. Resumo Técnico
Aplicação Web em Python composta por um backend assíncrono em FastAPI e uma interface web (HTML, CSS e JavaScript) servida pela própria API, totalmente empacotada em um contêiner Docker.
O sistema utiliza o banco vetorial ChromaDB (persistido em disco local com modelo de embeddings `gemini-embedding-001`) para o pipeline RAG e integra um Agente ReAct inteligente capaz de delegar cálculos matemáticos acadêmicos para funções determinísticas puras em Python via Function Calling.

## 2. Contexto Técnico e Stack Tecnológica
- **Linguagem & Versão:** Python 3.11+. Selecionado pelo suporte nativo a concorrência assíncrona (`asyncio`), tipagem estrita via Pydantic v2 e ecossistema robusto de IA.
- **Framework Web Backend:** FastAPI com Uvicorn. Oferece alta performance para I/O assíncrono, validação automática de dados e geração de documentação OpenAPI/Swagger.
- **Frontend:** página web própria (`src/web/index.html`), em HTML, CSS e JavaScript sem build, servida pela própria API na rota `/`. Dá controle total do visual (fontes clicáveis, cartão do cálculo) e dispensa um segundo servidor.
- **Framework de IA:** Google GenAI SDK / LangChain. Fornece abstração padronizada para Function Calling, gerenciamento de prompts e integração com modelos Gemini.
- **Modelo de IA & Embeddings:** Google Gemini (`gemini-flash-lite-latest`) para geração e `gemini-embedding-001` para vetorização (768 dimensões). Os modelos previstos originalmente (Gemini 1.5 Flash e `text-embedding-004`) foram descontinuados pelo Google.
- **Banco Vetorial:** ChromaDB (Persistência em Disco Local em `/app/data/vectordb`). Garante baixo overhead de infraestrutura e suporte a metadados estruturados por documento.
- **Testes & Evals:** Pytest. Utilizado em suíte automatizada para validações unitárias, de integração e métricas quantitativas do RAG e do Agente.
- **Empacotamento & Infraestrutura:** Docker e Docker Compose. Garantem isolamento completo de dependências, ambiente reproduzível e suporte a deploy em nuvem (Render, Railway, Fly.io ou Kubernetes).

## 3. Tabela de Verificação da Constituição

### Verificação da Constituição e Princípios Arquiteturais
- **Fundamentação das Respostas (RAG):**
  - *Como atende:* As consultas ao banco vetorial trazem metadados estruturados (`artigo`, `pagina`) vinculados aos trechos recuperados.
  - *Evidência:* Citação explícita exibida no contrato do payload JSON da API e renderizada na interface do usuário.
- **Determinismo em Cálculos:**
  - *Como atende:* O agente aciona a função `calcular_nota_final` via Function Calling sem delegar lógica matemática ao LLM.
  - *Evidência:* Testes unitários com Pytest aprovados com 100% de precisão no módulo da Tool (`src/tools/calculator.py`).
- **Segurança e Gestão de Segredos:**
  - *Como atende:* As credenciais e chaves de API são lidas exclusivamente do ambiente via `pydantic-settings`.
  - *Evidência:* Arquivo `.env.example` no repositório, ausência de chaves hardcoded no código e falha na inicialização caso `GEMINI_API_KEY` esteja ausente.
- **Empacotamento e Operação:**
  - *Como atende:* Toda a aplicação roda isolada dentro de um contêiner Docker reprodutível e otimizado.
  - *Evidência:* `Dockerfile` multi-stage e `docker-compose.yml` totalmente funcionais no projeto.
- **Avaliação do Sistema (Evals):**
  - *Como atende:* Suíte automatizada com 15 cenários de referência avaliando o pipeline RAG, acionamento de Tools e recusas de escopo.
  - *Evidência:* Execução de `pytest tests/evals/` gerando relatório detalhado de assertividade.


## 4. Arquitetura e Fluxo de Dados
+-----------------------------------------------------------------------------------------+
|                                    DISPOSITIVO CLIENTE                                  |
|                                       [ Navegador ]                                     |
+-----------------------------------------------------------------------------------------+
|
| HTTPS / WebSockets
v
+-----------------------------------------------------------------------------------------+
|                                  CAMADA DE INTERFACE (UI)                               |
|                        [ Página web servida pela API (src/web) ]                        |
+-----------------------------------------------------------------------------------------+
|
| REST / HTTP JSON (Port 8000)
v
+-----------------------------------------------------------------------------------------+
|                                  CAMADA DE BACKEND (API)                                |
|  [ FastAPI Application Server ]                                                         |
|                                                                                         |
|   +-----------------------+     +-------------------------+     +--------------------+  |
|   |   Orchestrator Agent  | --> |   RAG Retrieval Engine  | --> | ChromaDB (Vector)  |  |
|   +-----------------------+     +-------------------------+     +--------------------+  |
|               |                             | (Regimento em PDF)| (Persistência Local)  |
|               | (Decision / Tool Routing)   v                   +--------------------+  |
|               v                 +-------------------------+                             |
|   +-----------------------+     | System Prompt Builder   |                             |
|   | Agent Tool Executor   |     +-------------------------+                             |
|   | calcular_nota_final() |                 |                                           |
+---|-----------------------------------------|-------------------------------------------+
| Python Determinístico                   | gRPC / HTTPS API Calls
+------------------->                     v
+-----------------------------+
|     PROVEDOR EXTERNO IA     |
|  [ Google Gemini API Engine ]|
+-----------------------------+
## 5. Estratégias de Teste e Avaliação (Evals)

### Suíte de Testes Unitários e de Integração (Pytest)
- **Regra de Negócio e Cálculo Determinístico:**
  $$\text{Se } MP = \frac{P1 + P2}{2} \ge 7.0 \Rightarrow \text{Aprovado Direto}$$
  $$\text{Se } 4.0 \le MP < 7.0 \Rightarrow \text{Em Prova Final, com } NF = 12 - MP$$
  $$\text{Se } MP < 4.0 \Rightarrow \text{Reprovado Direto}$$
- **Validação de Contratos (Schemas):** Validação rigorosa de payloads de entrada e saída utilizando Pydantic v2 no FastAPI.
- **Pipeline de Ingestão:** Testes unitários para o extrator de PDF e fatiamento por regex de artigos (`src/rag/ingest.py`).
- **Integração de API:** Endpoint `POST /api/v1/chat` validando retornos HTTP 200, 422 (payload inválido) e 503 (indisponibilidade da API do LLM).
- **Banco Vetorial:** Teste de persistência e consulta sintética de embeddings no ChromaDB.

### Matriz de Avaliação de IA (Evals)
- **RAG - Regimento (6 casos):** 
  - *Foco:* Perguntas sobre trancamento, limite de faltas (25%), colação de grau e jubilamento.
  - *Métricas:* Context Recall $\ge 0.90$, Faithfulness $= 1.00$, precisão na citação do artigo e presença da palavra-chave exata.
- **Agente / Tool Execution (4 casos):** 
  - *Foco:* Pedidos explícitos de cálculo de nota final com variações de frases.
  - *Métricas:* Invocação correta da Tool (`calcular_nota_final`) e 100% de acerto no valor numérico retornado.
- **Fora do Escopo / Safety (3 casos):** 
  - *Foco:* Perguntas sobre esportes, receitas, opiniões políticas e tentativas de injeção de prompt.
  - *Métricas:* Refusal Rate $= 100\%$ com mensagem de recusa padronizada sem alucinações.
- **Casos de Borda e Erro (2 casos):** 
  - *Foco:* Notas informadas fora da escala ($P1 = 15.0$, $P2 = -3.0$) ou tipos inválidos.
  - *Métricas:* Mensagem amigável solicitando correção dos valores sem indisponibilizar o serviço (tratamento gracioso).

## 6. Observabilidade, Saúde e Operação

- **Structured Logging:** Formato JSON via biblioteca `structlog` injetando metadados correlacionais: `request_id`, `latency_ms`, `tool_called`, `rag_chunks_retrieved` e `model_name`.
- **Health Checks & Probes (Kubernetes Ready):**
  - `/health/liveness`: Retorna HTTP 200 se a aplicação FastAPI estiver ativa.
  - `/health/readiness`: Retorna HTTP 200 apenas se o banco vetorial ChromaDB estiver acessível e montado em disco.
- **Circuit Breaker, Timeouts & Resiliência:**
  - Timeout estrito de 6 segundos na chamada ao modelo Gemini.
  - Retry com Exponential Backoff via biblioteca `tenacity` para contornar erros de rate limit (HTTP 429).
  - Em caso de falha persistente ou timeout, o sistema retorna uma mensagem amigável de erro temporário, evitando travamentos na interface.
- **Runbook Simplificado:**
  - *Build local:* `docker build -t app-tira-duvidas:v1 .`
  - *Ingestão de PDFs:* `docker run --rm -v $(pwd)/data:/app/data app-tira-duvidas:v1 python -m src.rag.ingest`
  - *Execução dos Evals:* `python -m pytest tests/evals/`

## 7. Tabela de Riscos Técnicos e Mitigações

| Risco | Causa Raiz | Impacto | Mitigação Arquitetural |
| :--- | :--- | :--- | :--- |
| **Alucinação em normas acadêmicas** | Contexto insuficiente ou temperatura alta no LLM. | **Alto** (fornecer regras ou prazos falsos ao aluno). | Fixar `temperature=0.0`. Injetar System Prompt estrito: *"Responda APENAS com base nos trechos fornecidos. Se não houver evidência, responda que não encontrou no regimento."* |
| **Falha na chamada da Tool (Function Calling)** | Ambiguidade no prompt do usuário ou erro no parsing do JSON de resposta. | **Médio** (LLM tenta calcular internamente no texto e erra a conta). | Definição de entrada e saída via schema estrito Pydantic v2. Validação rigorosa dos tipos de dados antes do disparo da função Python. |
| **Estouro de Rate Limit da API de IA** | Múltiplas requisições simultâneas ou suítes de teste executando em paralelo. | **Médio** (bloqueio temporário por erro HTTP 429). | Implementar decorador de Retry com Exponential Backoff (`tenacity`) e realizar cache local de embeddings e respostas. |
| **Perda dos dados do Banco Vetorial** | Reinicialização do contêiner sem persistência configurada. | **Médio** (necessidade de reprocessar todos os PDFs). | Mapear volume persistente (`docker volume` ou Kubernetes `PersistentVolumeClaim`) apontando para `/app/data/vectordb`. |
| **Injeção de Prompt / Entradas Adversariais** | Usuários tentando burlar as instruções do sistema através do chat. | **Baixo/Médio** (sistema responder conteúdos não permitidos). | Sanitização das entradas no backend e separação estrita de papéis (System Prompt, User Input, Tool Output) na API. |