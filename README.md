# Tira-Dúvidas Acadêmico

Aplicação inicial de chat acadêmico com API FastAPI e interface Streamlit. A primeira fatia executável entrega o cálculo determinístico de notas; a consulta ao Regimento via RAG será conectada quando os PDFs forem adicionados.

## Requisitos

- Python 3.11 ou superior
- Dependências de `requirements.txt`

## Executar localmente

No PowerShell, a partir da raiz do projeto:

```powershell
py -3.11 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
Copy-Item .env.example .env
uvicorn src.api.main:app --reload --port 8000
```

Em outro terminal, com o mesmo ambiente virtual ativado:

```powershell
streamlit run src/frontend/app.py --server.port 8501
```

Abra `http://localhost:8501`. A documentação interativa da API fica em `http://localhost:8000/docs`.

## Primeira capacidade

O frontend tem três áreas: consulta em chat, calculadora de notas e informações sobre o serviço. Envie uma mensagem como `P1 5,5 e P2 6,0` ou use a calculadora para obter a média parcial e a nota necessária na Prova Final. A função Python valida notas de 0 a 10 e aplica os limites definidos em `Spec.md`.

As rotas são `POST /api/v1/chat`, `POST /api/v1/calcular-nota`, `GET /health/liveness` e `GET /health/readiness`. O chat recebe `session_id` e o histórico de mensagens. As citações da resposta são exibidas em cartões expansíveis quando a API retornar fontes.

## Segurança aplicada nesta etapa

- A API aceita origens CORS configuradas em `CORS_ORIGINS`; o padrão é `http://localhost:8501`.
- Os schemas rejeitam campos inesperados, limitam texto/histórico e validam notas de 0 a 10.
- Erros de validação e erros internos não incluem os valores enviados nem detalhes de implementação.
- Os logs registram identificador, rota, status e latência, sem salvar perguntas ou notas.
- A chave Gemini fica apenas no ambiente do backend; o frontend não recebe essa variável.
- O histórico permanece na sessão da interface e não é persistido no servidor.

Autenticação de usuários está fora do escopo desta versão, conforme `Spec.md`. Para publicação, configure HTTPS e restrinja o acesso no proxy/plataforma; não exponha a API diretamente à internet sem essa camada.

O readiness retorna HTTP 503 até que o ChromaDB persistente esteja conectado. A consulta documental também permanece em preparação até a ingestão dos PDFs.

## Próximas entregas

1. Colocar os PDFs autorizados em `data/raw/`.
2. Implementar extração, chunking por artigo, embeddings e persistência ChromaDB.
3. Integrar o SDK Google GenAI, Function Calling, limites de chamada e respostas fundamentadas com fontes.
4. Acrescentar logs estruturados sem conteúdo de mensagens, testes, evals e containerização conforme as fases de `task.md`.

Configure segredos em `.env`; não inclua chaves ou dados pessoais em commits.
