"""Interface do assistente acadêmico: consulta conversacional e calculadora."""

import os
import uuid

import httpx
import streamlit as st

API_URL = os.getenv("API_BASE_URL", "http://localhost:8000").rstrip("/")
TIMEOUT = httpx.Timeout(10.0, connect=2.0)

st.set_page_config(page_title="Apoio Acadêmico", page_icon="🎓", layout="wide")
st.markdown(
    """
    <style>
      .block-container {max-width: 1050px; padding-top: 2rem;}
      [data-testid="stMetric"] {background: #f4f7fb; padding: 1rem; border-radius: 12px;}
      .source-card {background: #f4f7fb; border-left: 4px solid #3874c8;
        padding: .8rem 1rem; border-radius: 6px; margin: .5rem 0;}
    </style>
    """,
    unsafe_allow_html=True,
)

st.title("🎓 Apoio Acadêmico")
st.caption("Consulte as normas institucionais e calcule sua média para a Prova Final.")

if "session_id" not in st.session_state:
    st.session_state.session_id = str(uuid.uuid4())
if "messages" not in st.session_state:
    st.session_state.messages = []


def get_service_status() -> tuple[bool, bool]:
    try:
        live = httpx.get(f"{API_URL}/health/liveness", timeout=TIMEOUT).is_success
    except httpx.HTTPError:
        return False, False
    try:
        ready = httpx.get(f"{API_URL}/health/readiness", timeout=TIMEOUT).is_success
    except httpx.HTTPError:
        ready = False
    return live, ready


with st.sidebar:
    st.header("Estado do serviço")
    live, ready = get_service_status()
    if live:
        st.success("API online")
    else:
        st.error("API indisponível")
    if ready:
        st.success("Documentos prontos para consulta")
    else:
        st.warning("Base documental ainda não configurada")
    st.divider()
    st.caption("As respostas sobre normas dependem dos documentos oficiais e devem exibir suas fontes.")
    if st.button("Limpar conversa", use_container_width=True):
        st.session_state.messages = []
        st.session_state.session_id = str(uuid.uuid4())
        st.rerun()

chat_tab, calculator_tab, about_tab = st.tabs(["💬 Consultar", "🧮 Calcular nota", "ℹ️ Sobre"])

with chat_tab:
    st.subheader("Converse com o assistente")
    st.caption("Pergunte sobre regras acadêmicas ou informe P1 e P2 para calcular sua situação.")

    for message in st.session_state.messages:
        with st.chat_message(message["role"]):
            st.markdown(message["content"])
            for source in message.get("sources", []):
                title = f"{source.get('documento', 'Documento')} · {source.get('artigo', 'Referência')} · p. {source.get('pagina', '?')}"
                with st.expander(title):
                    st.write(source.get("trecho", "Trecho indisponível."))
            if message.get("tool_called"):
                st.caption("✓ Resultado calculado pela ferramenta acadêmica.")

    prompt = st.chat_input("Ex.: Qual é o limite de faltas? ou P1 5,5 e P2 6,0")
    if prompt:
        st.session_state.messages.append({"role": "user", "content": prompt})
        with st.chat_message("user"):
            st.markdown(prompt)
        sources = []
        tool_called = None
        with st.chat_message("assistant"):
            try:
                response = httpx.post(
                    f"{API_URL}/api/v1/chat",
                    json={"session_id": st.session_state.session_id, "messages": st.session_state.messages},
                    timeout=TIMEOUT,
                )
                response.raise_for_status()
                payload = response.json()
                answer = payload["response"]
                sources = payload.get("sources", [])
                tool_called = payload.get("tool_called")
                st.markdown(answer)
                for source in sources:
                    title = f"{source.get('documento', 'Documento')} · {source.get('artigo', 'Referência')} · p. {source.get('pagina', '?')}"
                    with st.expander(title):
                        st.write(source.get("trecho", "Trecho indisponível."))
                if tool_called:
                    st.caption("✓ Resultado calculado pela ferramenta acadêmica.")
                st.caption(f"Resposta em {payload.get('latency_ms', 0):.0f} ms")
            except httpx.HTTPStatusError as exc:
                answer = "Não consegui processar essa mensagem. Confira os dados e tente novamente."
                if exc.response.status_code == 429:
                    answer = "Muitas solicitações em sequência. Aguarde um instante e tente novamente."
                st.error(answer)
                sources = []
            except (httpx.HTTPError, ValueError, KeyError):
                answer = "O serviço está temporariamente indisponível. Tente novamente em instantes."
                sources = []
                st.error(answer)
        st.session_state.messages.append(
            {"role": "assistant", "content": answer, "sources": sources, "tool_called": tool_called}
        )

with calculator_tab:
    st.subheader("Calculadora de média")
    st.write("Informe as notas de P1 e P2. O cálculo segue os critérios acadêmicos documentados.")
    with st.form("calculator_form"):
        col_p1, col_p2 = st.columns(2)
        with col_p1:
            p1 = st.number_input("Nota da P1", min_value=0.0, max_value=10.0, value=5.0, step=0.1, format="%.1f")
        with col_p2:
            p2 = st.number_input("Nota da P2", min_value=0.0, max_value=10.0, value=5.0, step=0.1, format="%.1f")
        submitted = st.form_submit_button("Calcular resultado", type="primary", use_container_width=True)

    if submitted:
        try:
            response = httpx.post(
                f"{API_URL}/api/v1/calcular-nota",
                json={"p1": p1, "p2": p2},
                timeout=TIMEOUT,
            )
            response.raise_for_status()
            result = response.json()
            status_labels = {
                "aprovado_direto": ("Aprovado diretamente", "success"),
                "prova_final": ("Precisa fazer a Prova Final", "info"),
                "reprovado_direto": ("Reprovado diretamente", "error"),
            }
            label, style = status_labels[result["status"]]
            metric_col, status_col = st.columns(2)
            metric_col.metric("Média parcial", f"{result['media_parcial']:.1f}")
            if result.get("nota_necessaria_final") is not None:
                status_col.metric("Nota necessária na final", f"{result['nota_necessaria_final']:.1f}")
            if style == "success":
                st.success(label)
            elif style == "error":
                st.error(label)
            else:
                st.info(label)
            st.caption("Cálculo informativo; a validação oficial cabe à Secretaria Acadêmica.")
        except httpx.HTTPError:
            st.error("Não foi possível calcular agora. Verifique se a API está disponível e tente novamente.")

with about_tab:
    st.subheader("Como funciona")
    st.markdown(
        """
        - **Consulta acadêmica:** deve responder com base no Regimento e no Manual, mostrando artigo e página.
        - **Cálculo:** MP = (P1 + P2) / 2. Com MP ≥ 7, aprovação direta; com 4 ≤ MP < 7, a nota da final é 12 − MP; com MP < 4, reprovação direta.
        - **Privacidade:** o histórico fica na sessão desta interface e não é gravado pela API nesta versão.
        - **Limite atual:** a interface já trata citações, mas elas só aparecerão após a ingestão dos PDFs e ativação do RAG.
        """
    )
