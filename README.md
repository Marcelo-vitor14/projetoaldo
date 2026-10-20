# Uni Search — Assistente Acadêmico Inteligente

Chat acadêmico com API FastAPI e interface web própria (HTML/CSS/JS). Um agente com Google Gemini entende a pergunta, busca os trechos do Regimento e do Manual do Aluno no ChromaDB (RAG) e responde citando artigo e página; quando o aluno informa P1 e P2, o Gemini chama a calculadora determinística em Python (Function Calling).

Os PDFs em `data/raw/` são de uma instituição fictícia (IUVA), criados para testes. Para usar documentos reais, troque os PDFs e rode a ingestão de novo.

## Requisitos

- Python 3.11 ou superior
- Dependências de `requirements.txt`

## Executar localmente

No PowerShell, a partir da raiz do projeto:

```powershell
py -3.11 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
Copy-Item .env.example .env   # coloque sua chave em GEMINI_API_KEY
python -m src.rag.ingest      # lê os PDFs de data/raw e cria a base em data/vectordb
uvicorn src.api.main:app --reload --port 8000
```

A chave do Gemini é gratuita em https://aistudio.google.com (Get API key). Se o Google responder "SERVICE_DISABLED", ative a Gemini API no projeto pelo link da mensagem de erro. A ingestão só precisa ser refeita quando os PDFs mudarem.

Abra `http://localhost:8000`. A interface (`src/web/index.html`, HTML/CSS/JS sem build) é servida pela própria API. A documentação interativa da API fica em `http://localhost:8000/docs`.

## Como funciona

A tela é um chat com perguntas de exemplo. Pergunte algo como `Quantas faltas posso ter numa disciplina de 60 horas?` ou `minha P1 deu cinco e meio e a P2 seis, passei?`. As fontes citadas viram botões que abrem o trecho original grifado num painel ao lado, e o resultado da calculadora aparece como um cartão na resposta.

- `src/rag/ingest.py`: extrai o texto dos PDFs, corta por artigo (`Art. N`) ou seção (`Seção N`), gera embeddings com `gemini-embedding-001` (768 dimensões) e grava no ChromaDB com documento, capítulo, artigo e página.
- `src/rag/retriever.py`: busca os trechos mais próximos da pergunta; trechos com distância de cosseno acima de 0,35 são descartados.
- `src/agent/core.py`: agente com Gemini (`temperature=0`), prompt restrito ao Regimento e ao Manual e duas ferramentas, `buscar_regimento` e `calcular_nota_final`. Repete a chamada com espera crescente em erros 429/500/503.
- `src/tools/calculator.py`: a conta é sempre feita em Python; o modelo só extrai P1 e P2.

Sem `GEMINI_API_KEY`, o chat volta ao modo simples: reconhece P1 e P2 por expressão regular e não consulta documentos. Os testes (`python -m pytest`) rodam nesse modo, sem internet.

As rotas são `POST /api/v1/chat`, `POST /api/v1/calcular-nota`, `GET /health/liveness` e `GET /health/readiness`. O chat recebe `session_id` e o histórico de mensagens, e devolve a resposta, as fontes citadas e, quando a calculadora é usada, o campo `calculo`.

## Segurança aplicada nesta etapa

- A API aceita origens CORS configuradas em `CORS_ORIGINS`; o padrão é `http://localhost:8000`. A interface web roda na mesma origem da API, então só outros clientes precisam ser liberados aqui.
- Os schemas rejeitam campos inesperados, limitam texto/histórico e validam notas de 0 a 10.
- Erros de validação e erros internos não incluem os valores enviados nem detalhes de implementação.
- Os logs registram identificador, rota, status e latência, sem salvar perguntas ou notas.
- A chave Gemini fica apenas no ambiente do backend; o frontend não recebe essa variável.
- O histórico permanece na sessão da interface e não é persistido no servidor.

Autenticação de usuários está fora do escopo desta versão, conforme `Spec.md`. Para publicação, configure HTTPS e restrinja o acesso no proxy/plataforma; não exponha a API diretamente à internet sem essa camada.

O readiness retorna HTTP 503 até que a ingestão tenha gravado trechos no ChromaDB.

## Próximas entregas

1. Trocar os PDFs fictícios pelos documentos oficiais da instituição.
2. Criar o dataset dos 15 evals e o script `src/evals/run_evals.py`.
3. Reduzir a latência (hoje entre 2 e 8 segundos por resposta) para a meta de 3,5 s.
4. Publicar em nuvem com HTTPS.

Configure segredos em `.env`; não inclua chaves ou dados pessoais em commits.
