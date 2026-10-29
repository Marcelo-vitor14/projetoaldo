# Uni Search — Sistemas Inteligentes Aplicados (Prof. Aldo)

Assistente acadêmico com IA (Gemini + RAG) que responde dúvidas sobre o Regimento Acadêmico e o Manual do Aluno do IUVA (Instituto Universitário Vale do Aldo, fictício).

| Pasta | O que é |
|---|---|
| [`2av/`](2av/README.md) | **2ª AV (MVP):** a API em FastAPI com a página de chat, como foi apresentada |
| [`3av/`](3av/README.md) | **3ª AV:** portal em Laravel (login do aluno com os dados dele, área da secretaria para enviar documentos, tempo real) ligado à API de IA |

Cada pasta tem o próprio README com como rodar. A chave do Gemini vai num `.env` (modelo no `.env.example` de cada parte), que nunca entra no Git.

Todos os dados (alunos, notas, regimento e manual) são fictícios, criados para o projeto.
