# Spec.md — Uni Search: Assistente Acadêmico Inteligente (antes "Tira-Dúvidas Acadêmico & Copiloto do Aluno")

## 1. Problema e Resultado Esperado
- **Usuário prioritário:** Alunos universitários e equipe da Secretaria / Coordenação Acadêmica.
- **Situação:** Sempre que o aluno precisa consultar prazos, normas de TCC, trancamento e limites de faltas, ou quando precisa descobrir a nota necessária na Prova Final após P1 e P2.
- **Problema observável:** Leitura trabalhosa de PDFs extensos e burocráticos (Regimento e Manual do Aluno) e erros frequentes no cálculo mental da nota necessária na Prova Final, gerando ansiedade nos estudantes e sobrecarga de chamados na secretaria.
- **Resultado esperado:** Aplicação web responsiva que responde a dúvidas em linguagem natural com citação exata das fontes (artigo, capítulo, seção, página) e calcula com 100% de precisão matemática a nota necessária na Prova Final por meio de uma ferramenta determinística acionada pelo agente.
- **Alternativa atual:** Busca manual nos arquivos PDF do Regimento/Manual do Aluno, atendimento presencial/e-mail com a secretaria e cálculos manuais em calculadoras ou planilhas de terceiros.

## 2. Escopo

### O que ESTÁ no escopo (In-Scope)
- Leitura, pré-processamento e indexação vetorial dos documentos do Regimento Acadêmico e Manual do Aluno.
- Respostas em linguagem natural fundamentadas via RAG, com citação explícita da fonte (artigo, capítulo, seção e página).
- Agente inteligente com capacidade de extrair parâmetros e acionar automaticamente a ferramenta determinística de cálculo de notas.
- Ferramenta (Tool) em Python puro para cálculo de Média Parcial ($MP$) e nota necessária na Prova Final ($NF$).
- Tratamento de perguntas fora do contexto acadêmico institucional com mensagem amigável de recusa (sem alucinação).
- Interface Web responsiva em formato de chat conversacional.
- Empacotamento em contêiner Docker para implantação com suporte a HTTPS.
- Mensagens orientativas e amigáveis para falhas de integração com a API do modelo de linguagem ou entradas inválidas.

### O que NÃO ESTÁ no escopo (Out-of-Scope)
- Integração direta com APIs ou banco de dados do portal do aluno para busca automática de notas autenticadas.
- Envio automático de requisições, chamados ou e-mails formais para a secretaria.
- Upload dinâmico de novos documentos em tempo de execução através da interface do usuário.
- Autenticação de usuários e controle de permissões por perfil nesta versão inicial.

## 3. Cenários de Usuário e Teste (Histórias de Usuário)

### História 1: Consulta sobre limite de faltas e frequência
- **Como** estudante do curso,
- **Quero** consultar a quantidade máxima de faltas permitida em uma disciplina,
- **Para que** eu evite a reprovação por frequência.
- **Cenário de Aceite:**
  - *Dado* que pergunto: "Quantas faltas posso ter numa disciplina de 60 horas?",
  - *Quando* o RAG busca a informação na base,
  - *Então* o sistema deve responder informando o limite exato (ex: 25% / 15 horas) e citar o artigo correspondente do Regimento.

### História 2: Cálculo de Nota para a Prova Final
- **Como** estudante que realizou as avaliações P1 e P2,
- **Quero** informar minhas notas e saber quanto preciso tirar na Prova Final,
- **Para que** eu planeje meus estudos para o exame.
- **Cenário de Aceite:**
  - *Dado* que informo: "Tirei 5.5 na P1 e 6.0 na P2, quanto preciso na Final?",
  - *Quando* o agente identifica a necessidade de cálculo,
  - *Então* ele executa a ferramenta `calcular_nota_final(p1=5.5, p2=6.0)` e exibe o resultado numérico exato com a regra de aprovação.

### História 3: Consulta fora do contexto acadêmico
- **Como** usuário da plataforma,
- **Quero** fazer uma pergunta não relacionada às regras da faculdade,
- **Para que** o sistema responda sem inventar dados.
- **Cenário de Aceite:**
  - *Dado* a pergunta: "Qual o melhor restaurante da cidade?",
  - *Quando* o RAG verifica a ausência de evidência na base documental,
  - *Então* o sistema deve informar educadamente que responde apenas sobre o regimento e manual acadêmico.

## 4. Casos de Borda
- **Entrada de notas inválidas:** Notas negativas ou superiores a 10 (ex: P1 = 12 ou P2 = -1). O sistema deve validar via Pydantic e solicitar valores válidos entre 0.0 e 10.0.
- **Média suficiente para aprovação direta:** Se $MP \ge 7.0$, a ferramenta deve informar que o aluno já está aprovado e não precisa realizar a Prova Final.
- **Média insuficiente para Prova Final:** Se $MP < 4.0$, a ferramenta deve informar que o aluno está reprovado diretamente.
- **Ausência de norma específica:** Se o aluno perguntar sobre um assunto não coberto no documento, o sistema deve assumir limitação e não alucinar regras inexistentes.

## 5. Requisitos Funcionais
- **RF01:** O sistema deve buscar trechos relevantes na base de vetores e retornar respostas acompanhadas das devidas referências.
- **RF02:** O agente deve extrair parâmetros numéricos do texto e acionar a função de cálculo de média via Function Calling quando identificada a intenção.
- **RF03:** A interface web deve permitir troca de mensagens em formato de chat responsivo.
- **RF04:** O sistema deve apresentar mensagem amigável em caso de erros de integração ou timeout na API do modelo de linguagem.
- **RF05:** O contrato da API deve aceitar o histórico da sessão ativa de mensagens enviado pelo cliente web para manter a continuidade do contexto.
- **RF06:** A interface deve destacar visualmente as fontes consultadas e o cartão com os resultados do cálculo de notas.

## 6. Entidades Principais
- **Chunk de Documento:** Trecho do texto extraído do regimento contendo conteúdo, metadados de localização (capítulo/artigo/página) e vetor de embedding.
- **Mensagem / Conversa:** Estrutura contendo o histórico de entradas do usuário, respostas do sistema, papel (user/assistant) e lista de fontes citadas.
- **Resultado do Cálculo:** Estrutura de dados contendo `p1`, `p2`, `media_parcial`, `status` e `nota_necessaria_final`.

## 7. Critérios de Sucesso e Requisitos de Qualidade
- **Acurácia RAG:** 100% das respostas baseadas em normas devem indicar a citação da fonte.
- **Determinismo Matemático:** 0% de erro nos cálculos de notas (garantido por execução Python pura via Tool).
- **Desempenho:** Tempo de resposta médio inferior a 3.5 segundos por requisição.
- **Disponibilidade:** Aplicação rodando em contêiner Docker publicado e operando via HTTPS.

## 8. Suposições e Dependências
- **Suposição:** Regimento e Manual estão disponíveis para conversão em formato de texto/Markdown/PDF em `data/raw/`.
- **Dependência:** Chave de API ativa para modelo de linguagem (Gemini API) configurada na variável de ambiente `GEMINI_API_KEY`.

## 9. Questões em Aberto e Decisões Confirmadas
- **Decisão Confirmada:** A regra de cálculo adota $MP = \frac{P1 + P2}{2}$.
  - Se $MP \ge 7.0 \Rightarrow \text{Aprovado Direto}$.
  - Se $4.0 \le MP < 7.0 \Rightarrow \text{Em Prova Final}$, com nota mínima exigida no exame de $NF = 12 - MP$.
  - Se $MP < 4.0 \Rightarrow \text{Reprovado Direto}$.
- **Decisão Confirmada:** O banco vetorial adotado será o **ChromaDB** embarcado em modo persistente local (`data/vectordb/`).

---

## Constitution

### Princípios

#### I. Evidência antes de afirmação
- **Necessidade de Fonte:** Toda resposta gerada pelo assistente referente a direitos, deveres, prazos, limites de faltas ou normas acadêmicas exige obrigatoriamente a recuperação de evidências diretas na base vetorial.
- **Apresentação da Fonte:** A resposta exibida na interface deve incluir a citação explícita do metadado recuperado do PDF (indicando obrigatoriamente Artigo e Página do Regimento ou Manual do Aluno).
- **Comportamento na Ausência de Evidência:** Se a busca vetorial não retornar trechos com relevância suficiente para a dúvida do usuário, o sistema deve emitir uma resposta padronizada declarando que não encontrou a norma no documento oficial, sendo estritamente proibido inventar, inferir ou extrapolar regras inexistentes.

#### II. Proteção de dados
- **Dados Proibidos:** É proibida a coleta, armazenamento ou processamento de informações de identificação pessoal (PII) dos estudantes, como CPF, matrícula, senha, endereço, foto ou dados financeiros.
- **Minimização de Dados:** O sistema processa apenas o texto digitado na mensagem do chat e as notas numéricas informadas voluntariamente para cálculo.
- **Retenção e Histórico:** O histórico de conversa e as notas informadas são mantidos exclusivamente na memória da sessão ativa do navegador e são descartados assim que a sessão é encerrada.
- **Cuidados com Logs:** Os logs estruturados (structlog) devem registrar apenas identificadores da requisição (`request_id`), métricas de desempenho (`latency_ms`), status HTTP e nome da ferramenta acionada, sendo proibido gravar o conteúdo completo do prompt do usuário ou chaves de API nos logs.

#### III. Verificação humana
- **Ações Sujeitas à Confirmação:** O assistente atua exclusivamente em caráter informativo e consultivo. Nenhuma resposta fornecida pelo sistema tem valor de ato administrativo ou lançamento oficial de notas.
- **Aprovação e Autoridade:** Decisões sobre abono de faltas, trancamento de matrícula, revisão de provas ou validação final de médias exigem obrigatoriamente a tramitação formal e aprovação da Secretaria Acadêmica ou Coordenação de Curso.

#### IV. Qualidade verificável
- **Testes Mínimos:** Todo Pull Request deve ser aprovado nos testes unitários e de integração (pytest) com cobertura mínima de 100% no módulo determinístico de cálculo de notas.
- **Métricas de Avaliação de IA (Evals):** O pipeline RAG e o Agente devem atingir aprovação no dataset de referência (15 casos de teste) garantindo Context Recall $\ge 0.90$, Faithfulness $= 1.00$ e 0% de erro aritmético.
- **Acessibilidade e Desempenho:** A interface web deve ser responsiva (desktop e mobile) com tempo médio de resposta da API inferior a 3.5 segundos.
- **Critério de Conclusão (Definition of Done):** Uma tarefa é considerada concluída quando possui código testado, passou pelos testes de Evals, está empacotada no contêiner Docker e possui documentação atualizada.

#### V. Entrega incremental
- **Demonstração Isolada:** As histórias de usuário prioritárias devem ser desenvolvidas e testadas de forma independente.
- **Estratégia de Validação:**
  - A história P1 (Consulta ao Regimento via RAG) deve ser demonstrada e validada antes da integração com a interface completa.
  - A história P2 (Cálculo Determinístico de Nota) deve ser validada isoladamente via testes unitários de código Python puro antes de ser acoplada ao Agente.
  - A história P3 (Tratamento de Perguntas Fora de Escopo) deve ser testada de forma autônoma garantindo a taxa de recusa correta.

### Restrições do Projeto
- **Tecnologia e Serviços Obrigatórios:** Desenvolvimento em Python 3.11+, backend com FastAPI, frontend web (HTML, CSS e JavaScript) servido pela própria API, banco vetorial ChromaDB (persistente em disco local) e execução containerizada via Docker Compose.
- **Provedor de Inteligência:** Uso do SDK oficial da API do Google Gemini com gerenciamento de chamadas de função (Function Calling).
- **Limites de Custo e Infraestrutura:** Custo zero de banco de dados por meio da execução de banco vetorial embarcado e infraestrutura otimizada para implantação em instâncias leves de nuvem (Render, Railway, Fly.io ou K8s).
- **Segurança e Licenciamento:** Chaves de API gerenciadas estritamente por variáveis de ambiente (`.env`). Projeto construído utilizando bibliotecas e frameworks de código aberto sob licenças permissivas (MIT, Apache 2.0 ou BSD).

### Governança
- **Aprovação de Alterações:** Qualquer modificação neste documento ou nos arquivos de arquitetura (`Plan.md` e `Spec.md`) exige validação e confirmação expressa do Tech Lead e do Product Owner.
- **Alinhamento do Código com os Princípios:** As especificações e planos de implementação devem registrar explicitamente nas tarefas (`Tasks.md`) e nos testes de validação como atendem a cada um dos princípios definidos neste documento.
- **Gestão de Exceções:** Caso seja necessário desviar provisoriamente de alguma restrição técnica ou princípio, o desenvolvedor deve registrar um item no arquivo `DTEC.md` detalhando o motivo técnico, o risco assumido, a alternativa rejeitada e a data limite para a revisão e correção do desvio.