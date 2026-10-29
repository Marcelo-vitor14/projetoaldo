"""Os testes rodam sem internet: sem chave do Gemini e com uma base vetorial vazia."""

import os
import tempfile

os.environ["GEMINI_API_KEY"] = ""
os.environ["IA_TOKEN"] = "token-de-teste"
TOKEN = {"X-IA-Token": "token-de-teste"}
os.environ["CHROMA_PERSIST_DIRECTORY"] = os.path.join(tempfile.mkdtemp(), "vectordb")
