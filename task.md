# Tasks.md — Checklist de Tarefas por Fases

## Fase 1: Alinhamento de Especificação e Configuração Inicial
- [ ] **[T1.1]** Validar e consolidar os arquivos de especificação (`plan.md` e `Spec.md`) com a equipe e o Product Owner.
- [ ] **[T1.2]** Inicializar a estrutura do repositório Git, criar o `.gitignore` (ignorando `.env`, `__pycache__` e diretórios de dados locais) e definir o fluxo de branches.
- [ ] **[T1.3]** Criar o arquivo `.env.example` contendo a variável `GEMINI_API_KEY` e configurações de ambiente.
- [ ] **[T1.4]** Configurar o gerenciador de dependências (`requirements.txt` ou `pyproject.toml`) com FastAPI, Uvicorn, Streamlit, ChromaDB, Structlog, Tenacity, Pydantic v2 e Pytest.
- [ ] **[T1.5]** Adicionar os documentos originais do Regimento Acadêmico e Manual do Aluno em PDF no diretório `data/raw/`.

## Fase 2: Ingestão de Dados e Construção do RAG
- [ ] **[T2.1]** Desenvolver o script de extração, limpeza e segmentação (chunking por Regex de Artigos) dos PDFs (`src/rag/ingest.py`), preservando metadados de capítulo, artigo e página. *Depende de: T1.5*
- [ ] **[T2.2]** Implementar o módulo de integração e persistência local do banco vetorial ChromaDB (`src/rag/vectorstore.py`) utilizando a função de embeddings `text-embedding-004` (768d). *Depende de: T2.1*
- [ ] **[T2.3]** Criar a ferramenta de busca vetorial (`src/rag/retriever.py`) empacotada com `asyncio.to_thread` e formatação estrita de citações e metadados. *Depende de: T2.2*
- [ ] **[T2.4]** Escrever testes unitários em Pytest para o pipeline de busca e recuperação de contexto. *Depende de: T2.3*

## Fase 3: Desenvolvimento da Tool e Agente Inteligente
- [ ] **[T3.1]** Implementar a função determinística Python `calcular_nota_final(p1, p2)` em `src/tools/calculator.py` com retorno Pydantic estrito, cobrindo aprovação direta ($MP \ge 7.0$), prova final ($4.0 \le MP < 7.0$, com $NF = 12 - MP$) e reprovação ($MP < 4.0$).
- [ ] **[T3.2]** Escrever testes unitários Pytest para a calculadora cobrindo cenários normais e casos de borda (notas fora da escala $0.0 \text{ a } 10.0$). *Depende de: T3.1*
- [ ] **[T3.3]** Criar o System Prompt estrito do assistente com instruções de fundamentação, recusa de temas fora de escopo e definições de schema Pydantic v2 para as Tools. *Depende de: T1.1*
- [ ] **[T3.4]** Implementar o orquestrador do Agente ReAct (`src/agent/core.py`) unindo o RAG retriever, a Tool de cálculo e a API do Gemini com retries com exponential backoff (`tenacity`). *Depende de: T2.3, T3.1, T3.3*

## Fase 4: API Backend e Interface do Usuário
- [ ] **[T4.1]** Desenvolver o servidor FastAPI (`src/api/main.py`) com as rotas `POST /api/v1/chat` (suportando payload com histórico de mensagens), `GET /health/liveness` e `GET /health/readiness`. *Depende de: T3.4*
- [ ] **[T4.2]** Implementar middleware de sanitização contra Prompt Injection e logging estruturado JSON com `structlog` registrando `request_id`, `latency_ms` e `tool_called`. *Depende de: T4.1*
- [ ] **[T4.3]** Criar os testes de integração do FastAPI via `httpx.AsyncClient` para respostas HTTP 200, 422 e 503. *Depende de: T4.1*
- [ ] **[T4.4]** Criar a interface conversacional em Streamlit (`src/frontend/app.py`) conectada ao backend FastAPI na porta `8000`. *Depende de: T4.1*
- [ ] **[T4.5]** Formatar a interface Streamlit para exibir seções de citações (artigo e página) e destacar os resultados da calculadora. *Depende de: T4.4*

## Fase 5: Evals, Containerização e Publicação
- [ ] **[T5.1]** Criar o dataset com os 15 casos de teste estruturados (6 RAG, 4 Tools, 3 Safety/Escopo e 2 Casos de Borda) em `tests/evals/dataset.json`. *Depende de: T1.1*
- [ ] **[T5.2]** Desenvolver o script de automação dos Evals (`src/evals/run_evals.py`) para mensurar acurácia RAG, precisão de Tools e taxa de recusa. *Depende de: T4.1, T5.1*
- [ ] **[T5.3]** Escrever o `Dockerfile` (multi-stage) e o `docker-compose.yml` mapeando volumes para a persistência do ChromaDB (`/app/data/vectordb`). *Depende de: T4.5*
- [ ] **[T5.4]** Realizar a implantação em ambiente de nuvem (Render, Railway, Fly.io ou K8s) configurando HTTPS e variáveis de ambiente. *Depende de: T5.3*
- [ ] **[T5.5]** Redigir o `README.md` completo com instrução de setup local, comandos Docker, execução de testes/evals e documentação das rotas da API. *Depende de: T5.4*

---

## Pontos de Paralelismo
- **Frente A (Engenharia de Dados e RAG):**
  - Responsável pelas tarefas **T2.1 a T2.4** (Ingestão de PDFs, ChromaDB e Retriever).
  - Na sequência, assume as tarefas **T5.1 e T5.2** (Construção do dataset e script de Evals de IA).
- **Frente B (Lógica do Agente e Ferramentas):**
  - Responsável pelas tarefas **T3.1 a T3.4** (Calculadora em Python, System Prompt e orquestrador ReAct).
  - Na sequência, apoia na construção das rotas do FastAPI (**T4.1 a T4.3**).
- **Frente C (Frontend e DevOps):**
  - Responsável pelas tarefas **T1.2 a T1.4** (Ambiente e projeto base) e avança para a interface Streamlit (**T4.4 e T4.5**).
  - Na sequência, finaliza a containerização e deploy (**T5.3 a T5.5**).