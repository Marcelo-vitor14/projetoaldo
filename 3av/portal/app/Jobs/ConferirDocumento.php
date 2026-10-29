<?php

namespace App\Jobs;

use App\Models\Documento;
use App\Services\IaApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Logo no envio, a IA só lê o PDF (sem embeddings, sem gastar cota) e diz em quantos trechos ele vira.
 * Assim um arquivo ruim aparece na hora, e não na publicação.
 */
class ConferirDocumento implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(public Documento $documento) {}

    public function handle(IaApi $ia): void
    {
        $resultado = $ia->verificarDocumento($this->documento);

        if ($resultado['ok']) {
            $this->documento->update(['status' => 'a_entrar', 'trechos' => $resultado['trechos'], 'erro' => null]);
        } elseif ($resultado['temporario'] ?? false) {
            throw new RuntimeException($resultado['erro']); // tenta de novo
        } else {
            $this->documento->update(['status' => 'invalido', 'erro' => $resultado['erro']]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->documento->update(['status' => 'invalido', 'erro' => 'A IA não respondeu para conferir o PDF. Cancele e envie de novo.']);
    }
}
