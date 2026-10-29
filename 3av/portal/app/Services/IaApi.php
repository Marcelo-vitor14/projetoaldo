<?php

namespace App\Services;

use App\Models\Cursada;
use App\Models\Documento;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Conversa com a API de IA (FastAPI). As regras do regimento ficam lá, num lugar só.
 * Toda chamada leva o token compartilhado: o navegador nunca fala direto com a IA.
 */
class IaApi
{
    /**
     * Pergunta ao assistente. Null se a IA estiver fora do ar ou o Gemini indisponível.
     *
     * @param  list<array{role: string, content: string}>  $mensagens  histórico, terminando na pergunta do aluno
     * @param  array<string, mixed>|null  $aluno  resumo acadêmico (Aluno::resumoParaIa), para perguntas sobre a situação dele
     * @return array{texto: string, fontes: list<array<string, mixed>>, calculo: array<string, mixed>|null, dados_aluno: list<array<string, mixed>>, ferramenta: string|null}|null
     */
    public function chat(array $mensagens, string $sessao, ?array $aluno = null): ?array
    {
        try {
            $resposta = $this->http(config('services.ia.timeout_chat'))
                ->post('/api/v1/chat', array_filter(['session_id' => $sessao, 'messages' => $mensagens, 'aluno' => $aluno]))
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            Log::warning('API de IA indisponível no chat', ['erro' => $e->getMessage()]);

            return null;
        }

        return [
            'texto' => (string) $resposta->json('response'),
            'fontes' => $resposta->json('sources') ?? [],
            'calculo' => $resposta->json('calculo'),
            'dados_aluno' => $resposta->json('dados_aluno') ?? [],
            'ferramenta' => $resposta->json('tool_called'),
        ];
    }

    /**
     * Igual a chat(), mas lê a resposta aos poucos: chama $aoReceber(pedaço) enquanto o Gemini escreve.
     * Devolve o mesmo resultado de chat() no fim, ou null se a IA/Gemini falhar.
     *
     * @param  list<array{role: string, content: string}>  $mensagens
     * @param  array<string, mixed>|null  $aluno
     * @param  callable(string): void  $aoReceber
     */
    public function chatAosPoucos(array $mensagens, string $sessao, ?array $aluno, callable $aoReceber): ?array
    {
        try {
            $resposta = $this->http(config('services.ia.timeout_chat'))
                ->withOptions(['stream' => true])
                ->post('/api/v1/chat/aos-poucos', array_filter(['session_id' => $sessao, 'messages' => $mensagens, 'aluno' => $aluno]));
        } catch (ConnectionException $e) {
            Log::warning('API de IA indisponível no chat', ['erro' => $e->getMessage()]);

            return null;
        }
        if (! $resposta->successful()) {
            Log::warning('API de IA recusou o chat', ['status' => $resposta->status()]);

            return null;
        }

        // Uma linha JSON por evento (NDJSON): lê conforme chega. Se a conexão cair no meio (ou passar do tempo),
        // o chat mostra "tente de novo" em vez de quebrar a página.
        $corpo = $resposta->toPsrResponse()->getBody();
        $resto = '';
        while (! $corpo->eof()) {
            try {
                $resto .= $corpo->read(1024);
            } catch (\RuntimeException $e) {
                Log::warning('A resposta da IA caiu no meio', ['erro' => $e->getMessage()]);

                return null;
            }
            while (($fim = strpos($resto, "\n")) !== false) {
                $evento = json_decode(substr($resto, 0, $fim), true);
                $resto = substr($resto, $fim + 1);
                if (($evento['tipo'] ?? null) === 'texto') {
                    $aoReceber((string) $evento['texto']);
                } elseif (($evento['tipo'] ?? null) === 'fim') {
                    return [
                        'texto' => (string) $evento['response'],
                        'fontes' => $evento['sources'] ?? [],
                        'calculo' => $evento['calculo'] ?? null,
                        'dados_aluno' => $evento['dados_aluno'] ?? [],
                        'ferramenta' => $evento['tool_called'] ?? null,
                    ];
                } elseif (($evento['tipo'] ?? null) === 'erro') {
                    Log::warning('Gemini indisponível no chat', ['detalhe' => $evento['detail'] ?? '']);

                    return null;
                }
            }
        }

        return null; // a conexão caiu antes do fim
    }

    /**
     * Situação de cada disciplina em curso, indexada pelo código. Null se a API estiver fora do ar.
     *
     * @param  Collection<int, Cursada>  $cursadas
     * @return array<string, array<string, mixed>>|null
     */
    public function situacoes(Collection $cursadas): ?array
    {
        if ($cursadas->isEmpty()) {
            return [];
        }

        $disciplinas = $cursadas->map(fn (Cursada $c) => [
            'codigo' => $c->disciplina->codigo,
            'carga_horaria' => $c->disciplina->carga_horaria,
            'faltas' => $c->faltas,
            'total_avaliacoes' => $c->disciplina->avaliacoes,
            'notas' => $c->valoresDasNotas(),
            'prova_final' => $c->prova_final,
        ])->values()->all();

        try {
            $resposta = $this->http(config('services.ia.timeout'))
                ->post('/api/v1/situacao-academica', ['disciplinas' => $disciplinas])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            Log::warning('API de IA indisponível ao calcular a situação', ['erro' => $e->getMessage()]);

            return null;
        }

        return collect($resposta->json('disciplinas'))->keyBy('codigo')->all();
    }

    /**
     * A IA só lê o PDF (sem embeddings) e diz em quantos trechos ele vira.
     *
     * @return array{ok: bool, trechos?: int, erro?: string, temporario?: bool}
     */
    public function verificarDocumento(Documento $documento): array
    {
        return $this->resultado(fn () => $this->http(config('services.ia.timeout_chat'))
            ->attach('arquivo', Storage::disk('local')->get($documento->caminho), $documento->nome_arquivo, ['Content-Type' => 'application/pdf'])
            ->post('/api/v1/documentos/verificar', ['nome' => $documento->nome]),
            fn ($r) => ['trechos' => (int) $r->json('trechos')]);
    }

    /**
     * Monta na IA uma versão nova e inteira da base com estes documentos (só troca se tudo der certo).
     *
     * @param  Collection<int, Documento>  $documentos
     * @return array{ok: bool, trechos?: int, documentos?: list<array{documento_id: string, trechos: int}>, erro?: string, temporario?: bool}
     */
    public function publicarBase(int $versao, Collection $documentos): array
    {
        return $this->resultado(function () use ($versao, $documentos) {
            $pedido = $this->http(config('services.ia.timeout_ingestao'));
            foreach ($documentos as $d) {
                $pedido->attach('arquivos', Storage::disk('local')->get($d->caminho), $d->nome_arquivo, ['Content-Type' => 'application/pdf']);
            }

            return $pedido->post('/api/v1/base', [
                'versao' => $versao,
                'documentos' => json_encode($documentos->map(fn (Documento $d) => ['documento_id' => $d->chave_ia, 'nome' => $d->nome])->values()),
            ]);
        }, fn ($r) => ['trechos' => (int) $r->json('trechos'), 'documentos' => $r->json('documentos') ?? []]);
    }

    /**
     * Volta para a versão anterior da base (na hora, sem reprocessar).
     *
     * @return array{ok: bool, versao?: int, erro?: string, temporario?: bool}
     */
    public function voltarBase(): array
    {
        return $this->resultado(fn () => $this->http(config('services.ia.timeout'))->post('/api/v1/base/voltar'),
            fn ($r) => ['versao' => (int) $r->json('versao')]);
    }

    /** Erros 5xx e IA fora do ar são temporários (vale tentar de novo); 4xx trazem o motivo. */
    private function resultado(callable $chamada, callable $dados): array
    {
        try {
            $resposta = $chamada();
        } catch (ConnectionException) {
            return ['ok' => false, 'erro' => 'A IA está fora do ar.', 'temporario' => true];
        }

        if ($resposta->successful()) {
            return ['ok' => true, ...$dados($resposta)];
        }

        return [
            'ok' => false,
            'erro' => (string) ($resposta->json('detail') ?? 'Erro '.$resposta->status().' na IA.'),
            'temporario' => $resposta->status() >= 500 || $resposta->status() === 409,
        ];
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl(config('services.ia.url'))
            ->withHeaders(['X-IA-Token' => (string) config('services.ia.token')])
            ->timeout($timeout)
            ->acceptJson();
    }
}
