"""Interface do assistente acadêmico: chat com o agente e calculadora rápida."""

import html
import os
import uuid

import httpx
import streamlit as st

API_URL = os.getenv("API_BASE_URL", "http://localhost:8000").rstrip("/")
TIMEOUT = httpx.Timeout(40.0, connect=2.0)

SUGESTOES = [
    ("📅", "Faltas", "Quantas faltas posso ter numa disciplina de 60 horas?"),
    ("⏸️", "Trancamento", "Até quando posso trancar a matrícula?"),
    ("🧮", "Nota da final", "Tirei 5,5 na P1 e 6 na P2. Quanto preciso na final?"),
    ("📚", "Biblioteca", "Quantos livros posso pegar na biblioteca e por quanto tempo?"),
]

STATUS_CALCULO = {
    "aprovado_direto": ("Aprovado direto", "ok"),
    "prova_final": ("Vai para a Prova Final", "warn"),
    "reprovado_direto": ("Reprovado direto", "off"),
}

CSS = """
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
html, body, .stMarkdown, .stButton button, input, textarea { font-family: 'Inter', sans-serif; }
.block-container { max-width: 860px; padding-top: 2rem; padding-bottom: 8rem; }
header[data-testid="stHeader"] { background: transparent; }

.hero { padding: 1.5rem 1.7rem; border-radius: 18px; border: 1px solid #252B3B; margin-bottom: 1.2rem;
  background: radial-gradient(120% 160% at 0% 0%, rgba(124,131,255,.25) 0%, rgba(11,13,18,0) 55%), #11141C; }
.hero .marca { color: #7C83FF; font-size: .78rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; }
.hero h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -.02em; margin: .2rem 0 .3rem; padding: 0; }
.hero p { color: #9AA3B5; margin: 0 0 .9rem; font-size: .95rem; }

.pill { display: inline-flex; align-items: center; gap: .45rem; font-size: .78rem; padding: .25rem .7rem;
  border-radius: 999px; background: #151925; border: 1px solid #252B3B; color: #C9CEDA; margin: .15rem .35rem .15rem 0; }
.dot { width: .5rem; height: .5rem; border-radius: 50%; display: inline-block; }
.dot.ok { background: #3DDC97; } .dot.warn { background: #F5B94A; } .dot.off { background: #FF6B6B; }
.dica { color: #7D8699; font-size: .85rem; margin: .6rem 0 .5rem; }

[class*="st-key-sugestao_"] button { min-height: 86px; width: 100%; border-radius: 14px; background: #11141C;
  border: 1px solid #252B3B; justify-content: flex-start; text-align: left; white-space: normal; padding: .8rem 1rem; }
[class*="st-key-sugestao_"] button:hover { border-color: #7C83FF; background: #151925; }
[class*="st-key-sugestao_"] button p { font-size: .9rem; line-height: 1.35; }

[data-testid="stChatMessage"] { background: #11141C; border: 1px solid #1E2331; border-radius: 16px; padding: .9rem 1.1rem; }
[data-testid="stChatMessage"]:has([data-testid="stChatMessageAvatarUser"]) { background: #1A1F33; border-color: #2A3150; }
[data-testid="stExpander"] details { border-radius: 12px; border: 1px solid #252B3B; background: #0F121A; }
[data-testid="stExpander"] summary p { font-size: .85rem; color: #C9CEDA; }
.tempo { color: #6B7385; font-size: .75rem; margin-top: .3rem; }

section[data-testid="stSidebar"] { border-right: 1px solid #1E2331; }
.resultado { border-radius: 14px; border: 1px solid #252B3B; background: #11141C; padding: .9rem 1rem; margin-top: .6rem; }
.resultado .num { font-size: 1.6rem; font-weight: 700; }
.resultado .rotulo { color: #9AA3B5; font-size: .78rem; }
.rodape { color: #6B7385; font-size: .75rem; margin-top: 1.2rem; }
</style>
"""


def pill(texto: str, estado: str) -> str:
    return f'<span class="pill"><span class="dot {estado}"></span>{html.escape(texto)}</span>'


@st.cache_data(ttl=15, show_spinner=False)
def estado_servico() -> tuple[bool, bool]:
    try:
        online = httpx.get(f"{API_URL}/health/liveness", timeout=TIMEOUT).is_success
    except httpx.HTTPError:
        return False, False
    try:
        pronto = httpx.get(f"{API_URL}/health/readiness", timeout=TIMEOUT).is_success
    except httpx.HTTPError:
        pronto = False
    return online, pronto


def nome_documento(arquivo: str) -> str:
    if arquivo.lower().startswith("regimento"):
        return "Regimento"
    if arquivo.lower().startswith("manual"):
        return "Manual do Aluno"
    return arquivo.rsplit(".", 1)[0]


def mostrar_extras(mensagem: dict) -> None:
    """Fontes citadas, selo da calculadora e tempo de resposta abaixo da mensagem do assistente."""
    for fonte in mensagem.get("sources", []):
        rotulo = f"📄 {nome_documento(fonte.get('documento', ''))} · {fonte.get('artigo', 'Referência')} · p. {fonte.get('pagina', '?')}"
        with st.expander(rotulo):
            st.write(fonte.get("trecho", "Trecho indisponível."))
    if mensagem.get("tool_called"):
        st.markdown(pill("Conta feita pela calculadora em Python, não pela IA", "ok"), unsafe_allow_html=True)
    if mensagem.get("latency_ms"):
        st.markdown(f'<div class="tempo">Respondido em {mensagem["latency_ms"] / 1000:.1f} s</div>', unsafe_allow_html=True)


def perguntar(historico: list[dict]) -> dict:
    """Envia o histórico à API e devolve a mensagem do assistente pronta para exibir."""
    try:
        resposta = httpx.post(
            f"{API_URL}/api/v1/chat",
            json={
                "session_id": st.session_state.session_id,
                "messages": [{"role": m["role"], "content": m["content"]} for m in historico],
            },
            timeout=TIMEOUT,
        )
        resposta.raise_for_status()
        dados = resposta.json()
        return {
            "role": "assistant",
            "content": dados["response"],
            "sources": dados.get("sources", []),
            "tool_called": dados.get("tool_called"),
            "latency_ms": dados.get("latency_ms"),
        }
    except httpx.HTTPStatusError as exc:
        texto = "Não consegui processar essa mensagem. Confira os dados e tente novamente."
        if exc.response.status_code == 429:
            texto = "Muitas solicitações em sequência. Aguarde um instante e tente novamente."
        elif exc.response.status_code == 503:
            texto = "O assistente está temporariamente indisponível. Tente novamente em instantes."
    except (httpx.HTTPError, ValueError, KeyError):
        texto = "O serviço está temporariamente indisponível. Tente novamente em instantes."
    return {"role": "assistant", "content": f"⚠️ {texto}", "erro": True}


st.set_page_config(page_title="Tira-Dúvidas Acadêmico", page_icon="🎓", layout="centered")
st.markdown(CSS, unsafe_allow_html=True)

if "session_id" not in st.session_state:
    st.session_state.session_id = str(uuid.uuid4())
if "messages" not in st.session_state:
    st.session_state.messages = []

# ---------- Barra lateral: calculadora rápida ----------
with st.sidebar:
    st.markdown("### 🧮 Calculadora rápida")
    st.caption("MP = (P1 + P2) / 2 · aprovado com 7 · final com 12 − MP")
    with st.form("calculadora", border=False):
        col_p1, col_p2 = st.columns(2)
        p1 = col_p1.number_input("P1", min_value=0.0, max_value=10.0, value=5.0, step=0.5, format="%.1f")
        p2 = col_p2.number_input("P2", min_value=0.0, max_value=10.0, value=5.0, step=0.5, format="%.1f")
        calcular = st.form_submit_button("Calcular", type="primary", use_container_width=True)
    if calcular:
        try:
            r = httpx.post(f"{API_URL}/api/v1/calcular-nota", json={"p1": p1, "p2": p2}, timeout=TIMEOUT)
            r.raise_for_status()
            resultado = r.json()
            rotulo, estado = STATUS_CALCULO[resultado["status"]]
            final = resultado.get("nota_necessaria_final")
            bloco_final = (
                f'<div style="margin-top:.5rem"><div class="rotulo">Precisa na final</div><div class="num">{final:.2f}</div></div>'
                if final is not None
                else ""
            )
            st.markdown(
                f'<div class="resultado"><div class="rotulo">Média parcial</div>'
                f'<div class="num">{resultado["media_parcial"]:.2f}</div>{bloco_final}'
                f'<div style="margin-top:.6rem">{pill(rotulo, estado)}</div></div>',
                unsafe_allow_html=True,
            )
        except httpx.HTTPError:
            st.error("Não foi possível calcular agora. Verifique se a API está no ar.")

    st.divider()
    with st.expander("Como funciona"):
        st.markdown(
            "- O **Google Gemini** entende a pergunta.\n"
            "- A resposta vem só de trechos do **Regimento** e do **Manual**, buscados no banco vetorial (RAG), "
            "sempre com artigo e página.\n"
            "- Contas de nota são feitas pela **calculadora em Python**, nunca pela IA.\n"
            "- O histórico fica só nesta sessão; nada é gravado no servidor."
        )
    if st.button("🗑️ Nova conversa", use_container_width=True):
        st.session_state.messages = []
        st.session_state.session_id = str(uuid.uuid4())
        st.rerun()
    st.markdown(
        '<div class="rodape">Projeto acadêmico ALDO. Os documentos do IUVA são fictícios. '
        "Decisões oficiais cabem à Secretaria Acadêmica.</div>",
        unsafe_allow_html=True,
    )

# ---------- Cabeçalho ----------
online, pronto = estado_servico()
st.markdown(
    '<div class="hero"><div class="marca">IUVA · Apoio acadêmico</div>'
    "<h1>🎓 Tira-Dúvidas Acadêmico</h1>"
    "<p>Pergunte sobre faltas, prazos, provas e TCC, ou informe suas notas. "
    "As respostas citam o artigo e a página do documento oficial.</p>"
    + pill("API online" if online else "API fora do ar", "ok" if online else "off")
    + pill("Documentos indexados" if pronto else "Documentos não indexados", "ok" if pronto else "warn")
    + pill("IA: Google Gemini", "ok" if online else "off")
    + "</div>",
    unsafe_allow_html=True,
)

prompt = st.chat_input("Pergunte sobre faltas, trancamento, TCC ou informe suas notas…")
prompt = st.session_state.pop("pendente", None) or prompt

# ---------- Tela inicial com sugestões ----------
if not st.session_state.messages and not prompt:
    st.markdown('<div class="dica">Comece por uma destas perguntas:</div>', unsafe_allow_html=True)
    colunas = st.columns(2)
    for indice, (icone, titulo, pergunta) in enumerate(SUGESTOES):
        if colunas[indice % 2].button(f"{icone} **{titulo}** — {pergunta}", key=f"sugestao_{indice}"):
            st.session_state.pendente = pergunta
            st.rerun()

# ---------- Conversa ----------
for mensagem in st.session_state.messages:
    with st.chat_message(mensagem["role"], avatar="🧑‍🎓" if mensagem["role"] == "user" else "🎓"):
        st.markdown(mensagem["content"])
        if mensagem["role"] == "assistant":
            mostrar_extras(mensagem)

if prompt:
    st.session_state.messages.append({"role": "user", "content": prompt})
    with st.chat_message("user", avatar="🧑‍🎓"):
        st.markdown(prompt)
    with st.chat_message("assistant", avatar="🎓"):
        with st.spinner("Consultando o regimento…"):
            resposta = perguntar([m for m in st.session_state.messages if not m.get("erro")])
        st.markdown(resposta["content"])
        mostrar_extras(resposta)
    st.session_state.messages.append(resposta)
