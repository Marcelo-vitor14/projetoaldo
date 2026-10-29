## Registro de Decisões Técnicas de Engenharia (DTEC)
Funcionalidade: Consulta de Regimento Acadêmico e Cálculo Determinístico de Nota Final
Data: 13/10/2026
Use este arquivo para resolver dúvidas técnicas que impedem a execução do plano. Questões de produto ou regras de negócio devem ser direcionadas ao Product Owner e à especificação.
## Decisão DTEC 001 — Escolha da Engine de Banco Vetorial para Persistência de Embeddings
Questão: Qual banco vetorial deve ser utilizado para indexar e buscar os trechos do Regimento Acadêmico e Manual do Aluno no pipeline RAG?
Critérios: Compatibilidade com Python 3.11, custo zero de infraestrutura gerenciada, facilidade de persistência em contêiner Docker, baixo consumo de memória e simplicidade de configuração pela equipe.
## Alternativas consideradas:Pinecone ou Qdrant Cloud: Oferecem busca vetorial escalável como serviço SaaS, mas introduzem dependência de rede externa, custos em escala e necessidade de gerenciamento de chaves adicionais.
ChromaDB em Modo Embarcado (Persistent Client): Armazena vetores diretamente em arquivo/diretório local no disco, possui integração nativa com ecossistemas Python RAG e elimina a necessidade de subir um serviço/servidor separado.
FAISS (Facebook AI Similarity Search): Extremamente rápido em memória, mas exige implementação manual de mecanismos para persistência e recuperação de metadados estruturados (capítulo, artigo, página).
Decisão: Opção 2 — ChromaDB em Modo Embarcado com armazenamento persistente em diretório local do projeto.
Justificativa: O volume de dados do Regimento e Manual do Aluno é de pequeno/médio porte (dezenas a poucas centenas de páginas), tornando um banco vetorial in-process a solução ideal. O ChromaDB gerencia nativamente a associação entre vetores e metadados de citação sem custos de infraestrutura nem dependência de serviços em nuvem terceirizados.

Vantagens: Arquitetura simples, execução local offline para testes, custo zero de banco e deploy simplificado no Docker via mapeamento de volume.
Limitações: Escalabilidade vertical limitada pela memória do contêiner caso o volume de documentos cresça para gigabytes (fora do cenário acadêmico atual).
Trabalho futuro: Mapear o diretório de dados para um docker volume ou PersistentVolumeClaim (K8s) para garantir a preservação dos dados após reinicializações.
Condição para revisão: Necessidade de suportar múltiplos campi/universidades simultaneamente com milhões de documentos e busca concorrente de alta escala.
## Fontes consultadas:
Documentação Oficial do ChromaDB (PersistentClient): [https://docs.trychroma.com/](https://docs.trychroma.com/)
Experimento de ingestão local e benchmarks de memória realizados na Fase 2 do projeto.

## Decisão DTEC 002 — Mecanismo de Garantia de Determinismo em Cálculos Matemáticos (Tool Execution)
Questão: Como garantir que as contas de Média Parcial ($MP$) e Nota Final ($NF$) não sofram alucinações numéricas por parte do Modelo de Linguagem (LLM)?
Critérios: Segurança e precisão matemática de 100%, compatibilidade com a API do Gemini, baixo tempo de resposta e facilidade de teste unitário via Pytest.
## Alternativas consideradas:Cálculo direto via Prompt Engineering (Chain-of-Thought): Instruir o LLM a realizar a conta passo a passo no texto. 
1.Cálculo direto via Prompt Engineering (Chain-of-Thought): Instruir o LLM a realizar a conta passo a passo no texto. Apresenta risco de erro aritmético, alucinação em valores decimais e dependência da temperatura do modelo.
2.Function Calling / Agent Tool Execution via Python puro: Definir um schema estrito (Pydantic v2) para a função calcular_nota_final(p1, p2) e delegar a execução da conta para o interpretador Python backend.
Decisão: Opção 2 — Execução determinística via Function Calling (Agent Tool) em Python puro.
Justificativa: Modelos de linguagem são geradores probabilísticos de texto e não calculadoras confiáveis. O uso de Function Calling garante 0% de erro aritmético e permite validar os limites numéricos ($0.0 \le \text{nota} \le 10.0$) através de código determinístico testado por Pytest.

Vantagens: Precisão matemática absoluta, desacoplamento da lógica de negócio em relação ao LLM e facilidade de testes unitários automatizados.
Limitações: Exige que o modelo extraia corretamente os parâmetros numéricos da mensagem do usuário antes de acionar a Tool.
Trabalho futuro: Adicionar validação de tipo e tratamento de exceção estrito para parâmetros ausentes ou fora da escala permitida.
Condição para revisão: Alteração drástica na API do provedor de IA que descontinue o suporte padrão a chamadas de função (Function Calling / Tool Use).
## Fontes consultadas:
Documentação da API Google GenAI / Gemini Function Calling.
Suíte de testes unitários do módulo src/tools/calculator.py.
## Decisão DTEC 003 — Estratégia de Isolamento de Camadas e Desacoplamento do Frontend/Backend
Questão: Como estruturar a comunicação entre a interface do usuário e a inteligência do sistema (RAG, Agente e Tools)?
Critérios: Separação de responsabilidades, facilitando manutenção, capacidade de reutilização do backend para outras interfaces (como apps móveis) e suporte a testes de integração isolados.
## Alternativas consideradas:
Aplicação Monolítica Única: Executar a lógica RAG, o agente e a interface no mesmo código de tela. Facilita o protótipo inicial, mas dificulta testes unitários da API, acopla a lógica à interface e impede reutilização do backend.
Arquitetura Desacoplada (Backend FastAPI + Frontend web): O backend expõe uma API REST/HTTP assíncrona (POST /api/v1/chat) e a interface web atua exclusivamente como cliente HTTP visual consumindo o contrato JSON.
Decisão: Opção 2 — Arquitetura desacoplada utilizando FastAPI como servidor de API e uma página web como cliente de interface.
Justificativa: A separação permite testar a inteligência do sistema via endpoints HTTP assíncronos e suítes de Evals automatizadas independentemente da interface gráfica, e permite trocar a interface sem mexer na API (o que de fato aconteceu, ver DTEC 004).

Vantagens: Código limpo, desacoplado, facilidade de instrumentação com structlog/OpenTelemetry e suporte a múltiplos clientes consumidores.
Limitações: A interface depende do contrato JSON da API; mudanças no contrato precisam ser refletidas na página.
Trabalho futuro: Adicionar suporte a Streaming responses (Server-Sent Events) no endpoint do FastAPI para exibir respostas caractere a caractere no frontend.
Condição para revisão: Necessidade de simplificar drásticamente a infraestrutura para rodar em ambientes com restrição severa de memória em desenvolvimento local.
## Fontes consultadas:
Documentação Oficial do FastAPI (Async Endpoints e Pydantic Integration).
Padrão Arquitetural C4 Model (Container Diagram).

## Decisão DTEC 004 — Interface web própria no lugar do Streamlit
Data: 15/10/2026
Questão: A restrição original da Spec previa o frontend em Streamlit. Ele atende ao que a apresentação do MVP precisa mostrar?
Critérios: Clareza na demonstração (fontes clicáveis com o trecho original, resultado do cálculo destacado conforme RF06), controle do visual, simplicidade de execução e de implantação.
## Alternativas consideradas:
1. Manter o Streamlit: rápido de escrever em Python, mas com pouco controle de layout e interação; exige um segundo servidor (porta 8501) e um segundo serviço no Docker Compose.
2. Página web própria servida pela API: um arquivo HTML/CSS/JS, sem build, entregue pela rota "/" do FastAPI.
Decisão: Opção 2 — interface web própria em src/web/index.html. O Streamlit (src/frontend) foi removido, junto com a dependência, o serviço do Docker Compose e a variável API_BASE_URL.
Justificativa: A página web permite citações clicáveis que abrem o artigo grifado e o cartão do cálculo dentro da resposta, e roda no mesmo servidor e na mesma origem da API (sem CORS, uma porta só). Como a arquitetura já era desacoplada (DTEC 003), a troca não exigiu mudança na API além da rota "/".
Vantagens: Um processo a menos para subir, visual alinhado ao objetivo da demonstração, nenhuma dependência de frontend para instalar.
Limitações: A interface é escrita em JavaScript, fora da linguagem principal do projeto.
Condição para revisão: Necessidade de telas administrativas complexas, em que um framework de UI volte a compensar.