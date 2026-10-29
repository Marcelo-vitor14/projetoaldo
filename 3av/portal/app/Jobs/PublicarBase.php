<?php

namespace App\Jobs;

use App\Models\Documento;
use App\Models\Publicacao;
use App\Services\IaApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * "Atualizar a base do assistente": a IA monta uma versão nova inteira, do zero, com todos os documentos
 * da próxima versão, e só troca a ativa se tudo der certo. Se falhar, nada muda (nem na IA, nem aqui).
 */
class PublicarBase implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [30, 120];

    public function __construct(public Publicacao $publicacao) {}

    public function handle(IaApi $ia): void
    {
        $documentos = Documento::daProximaVersao()->orderBy('id')->get();
        $resultado = $ia->publicarBase($this->publicacao->versao, $documentos);

        if (! $resultado['ok']) {
            if ($resultado['temporario'] ?? false) {
                throw new RuntimeException($resultado['erro']); // a fila tenta de novo; na última, failed()
            }
            $this->publicacao->update(['status' => 'falhou', 'erro' => $resultado['erro']]);

            return;
        }

        $trechos = collect($resultado['documentos'])->pluck('trechos', 'documento_id');

        DB::transaction(function () use ($documentos, $resultado, $trechos) {
            Publicacao::where('status', 'anterior')->update(['status' => 'descartada']);
            Publicacao::where('status', 'ativa')->update(['status' => 'anterior']);
            $this->publicacao->update([
                'status' => 'ativa',
                'trechos' => $resultado['trechos'],
                'documentos' => $documentos->map(fn (Documento $d) => ['id' => $d->id, 'nome' => $d->nome])->values()->all(),
                'erro' => null,
            ]);

            foreach ($documentos as $d) {
                $d->update(['status' => 'publicado', 'substitui_id' => null, 'trechos' => $trechos[$d->chave_ia] ?? $d->trechos]);
            }
            Documento::where('status', 'a_sair')->update(['status' => 'removido']);
        });

        // Removidos só ficam guardados enquanto a versão anterior ainda os usa (para poder voltar)
        $daAnterior = Publicacao::anterior()?->idsDosDocumentos() ?? [];
        Documento::where('status', 'removido')->whereNotIn('id', $daAnterior)->get()->each->delete();
    }

    public function failed(?Throwable $e): void
    {
        $this->publicacao->update([
            'status' => 'falhou',
            'erro' => 'A IA não conseguiu montar a base depois de 3 tentativas ('.($e?->getMessage() ?? 'erro desconhecido').'). A versão atual continua valendo.',
        ]);
    }
}
