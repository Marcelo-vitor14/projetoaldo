# Uni Search — versão da 3ª AV

Portal do aluno e da secretaria: a versão da 3ª AV do Uni Search. A versão da 2ª AV (MVP) está em [`../2av/`](../2av/README.md).

| Pasta | O que é |
|---|---|
| `ia/` | A API de IA (FastAPI + Gemini + RAG), a mesma da 2ª AV (versão `ad5b717`), com as rotas novas de ingestão, situação do aluno e resposta aos poucos |
| `portal/` | O portal em Laravel (login, perfil do aluno, painel da secretaria, tempo real) |

A versão apresentada no MVP continua intacta em [`../2av/`](../2av/).

## O que funciona
- **Login único** por matrícula (aluno) ou e-mail (secretaria), com perfis separados (spatie/laravel-permission), 2FA e passkeys opcionais. Sem cadastro público, e o aluno não edita nome nem e-mail (vêm da secretaria).
- **Perfil do aluno** com curso, período, semestre atual e histórico. As médias e situações vêm da API, que aplica o regimento (média de 1 a 10 notas, prova final, limite de 25% de faltas).
- **Chat** com várias conversas na barra lateral, histórico de 7 dias e botão de apagar. Sempre abre numa conversa nova, avisa a partir de 15 perguntas e trava em 20. A resposta aparece digitando; embaixo dela, o cartão da nota (com régua) e as etiquetas das fontes, que abrem um painel lateral com o trecho do artigo e o link para o PDF na página certa. Aviso fixo de que a IA é um auxiliar e pode errar.
- **Perguntas sobre a própria situação** ("eu passei em BD?", "qual minha média geral?", "posso fazer TCC?", "corro risco de jubilamento?", "quanto preciso na P3?"). O portal manda o resumo acadêmico sem nome, matrícula ou e-mail; o Gemini só vê o que a pergunta pediu, e a resposta mostra o cartão "Consultei seus dados". **Toda conta é feita pelo código** (média, progresso no curso, prazo, TCC, estágio, jubilamento, bolsa, nota para a meta, limite de faltas); a IA só explica e cita o artigo.
- **Área da secretaria** com rascunho e publicação: enviar (PDF até 20 MB, conferido na hora), tirar ou substituir documentos só muda o rascunho; o botão **Atualizar a base do assistente** monta uma versão nova inteira e só troca quando ela fica completa. Histórico de versões e **voltar para a anterior** na hora. Com artigos, o PDF é cortado por artigo; sem artigos, por parágrafos.
- **Tempo real:** o painel da secretaria se atualiza na hora pelo Laravel Reverb (canal privado só da secretaria), e a resposta do chat aparece aos poucos, enquanto o Gemini escreve.
- **Segurança:** a API de IA só responde ao portal (token `IA_TOKEN`); o navegador nunca fala direto com ela.

Falta: documentação final e apresentação (10).

**Para usar tudo junto**, são 5 peças, cada uma num terminal:
```
cd ia && .venv/bin/python -m uvicorn src.api.main:app --port 8000   # API de IA
cd portal && php artisan serve --port 8100                          # portal
cd portal && php artisan reverb:start                               # tempo real (porta 8080)
cd portal && php artisan queue:work                                 # fila (conferir e publicar documentos)
cd portal && php artisan schedule:work                              # agendador (apaga conversas antigas)
```

Testes: `cd portal && php artisan test` (75) e `cd ia && .venv/bin/python -m pytest -q` (117).

**Modelo do Gemini** (`ia/.env`): o principal é o `gemini-3-flash-preview` (pensa antes de responder, com raciocínio baixo). Se ele der erro 429 (limite por minuto da chave gratuita), a IA usa sozinha o `GEMINI_MODEL_RESERVA` (`gemini-flash-lite-latest`) por 1 minuto.

Para conferir e publicar documentos, o trabalhador da fila precisa estar ligado (`php artisan queue:work`); o Reverb é o `php artisan reverb:start` (porta 8080). As chaves do Reverb ficam no `.env` (modelo no `.env.example`). Na IA, a versão ativa da base fica em `ia/data/vectordb/base_ativa.json`.

## Rodar a IA
```
cd ia
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
cp .env.example .env   # e colocar a GEMINI_API_KEY e o IA_TOKEN
.venv/bin/python -m pytest -q
.venv/bin/python -m uvicorn src.api.main:app --port 8000
```

## Rodar o portal
```
cd portal
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate:fresh --seed
php artisan serve
```
O `IA_TOKEN` do `portal/.env` tem que ser **o mesmo** do `ia/.env`: é o segredo que deixa só o portal chamar a IA (as rotas `/api/` da IA recusam qualquer chamada sem ele, e sem token configurado recusam tudo). Gerar com `python3 -c "import secrets; print(secrets.token_urlsafe(32))"`.

O chat é para dúvidas rápidas: sempre abre numa conversa nova, avisa a partir de 15 perguntas e trava em 20 (`CHAT_AVISO_PERGUNTAS`, `CHAT_LIMITE_PERGUNTAS`). As conversas ficam guardadas por 7 dias (`CHAT_HISTORICO_DIAS`). Elas somem ao abrir o chat e, para quem não abre, pelo agendador (`php artisan schedule:work`).

A tela de chat antiga, servida pela própria IA, saiu desta versão: a interface agora é o portal. A do MVP continua em `../2av/`.

Precisa de PHP 8.3+ com as extensões `pdo_sqlite`, `sqlite3`, `intl`, `iconv` e `bcmath`.

### Usuários de teste (fictícios)
Senha de todos: `password`. Cada aluno mostra uma situação diferente no semestre atual (2026.2):

| Login | Quem | Situação para testar |
|---|---|---|
| `202310042` | Ana Beatriz Souza, 8º período | Prova final em Sistemas de Apoio à Decisão (5,5 e 6: precisa de 6,25); reprovação antiga em Cálculo I |
| `202410015` | Bruno Henrique Lima, 6º | Reprovado por falta em Sistemas Distribuídos (18 faltas, limite 15) |
| `202420007` | Carla Mendes Rocha, 5º | Reprovada por nota em Pesquisa Operacional (média 3,75) |
| `202510088` | Diego Alves Pereira, 4º | Aprovado direto em tudo |
| `202520031` | Elisa Fernandes Costa, 3º | Quase no limite de faltas em Banco de Dados I (19 de 20) |
| `secretaria@iuva.edu.br` | Secretaria Acadêmica | Painel da secretaria |

Há mais 20 alunos gerados automaticamente (matrículas terminadas em 0101 a 0120). As médias e situações do semestre atual vêm da API de IA (`/api/v1/situacao-academica`), que precisa estar rodando; sem ela, o perfil abre com as notas e um aviso.

A matrícula segue o formato AAAASNNNN: ano + semestre de ingresso + número (202310042 = 2023, 1º semestre, aluna 42).
