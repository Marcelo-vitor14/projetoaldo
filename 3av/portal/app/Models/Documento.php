<?php

namespace App\Models;

use App\Events\DocumentosMudaram;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Situações de um documento no rascunho:
 * conferindo → a_entrar (ou invalido) → publicado → a_sair → removido (guardado só enquanto a versão anterior o usa).
 */
#[Fillable(['nome', 'nome_arquivo', 'caminho', 'chave_ia', 'substitui_id', 'tamanho', 'status', 'trechos', 'erro', 'enviado_por'])]
class Documento extends Model
{
    public const ROTULOS = [
        'conferindo' => 'Conferindo…',
        'invalido' => 'Com erro',
        'a_entrar' => 'Vai entrar',
        'publicado' => 'Publicado',
        'a_sair' => 'Vai sair',
        'removido' => 'Removido',
    ];

    public const CORES = ['conferindo' => 'zinc', 'invalido' => 'red', 'a_entrar' => 'blue', 'publicado' => 'green', 'a_sair' => 'amber', 'removido' => 'zinc'];

    public function remetente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    /** O documento que este vai substituir quando a base for atualizada. */
    public function substitui(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'substitui_id');
    }

    /** O que aparece no painel (os removidos ficam escondidos, só para poder voltar de versão). */
    public function scopeVisiveis(Builder $query): void
    {
        $query->where('status', '!=', 'removido');
    }

    /** Os que vão compor a próxima versão da base. */
    public function scopeDaProximaVersao(Builder $query): void
    {
        $query->whereIn('status', ['publicado', 'a_entrar']);
    }

    public function rotulo(): string
    {
        return self::ROTULOS[$this->status] ?? $this->status;
    }

    /** Ex.: "86 KB" ou "2,4 MB". */
    public function tamanhoLegivel(): string
    {
        return $this->tamanho >= 1048576
            ? number_format($this->tamanho / 1048576, 1, ',', '').' MB'
            : max(1, round($this->tamanho / 1024)).' KB';
    }

    /** Apaga o PDF junto com o registro; e avisa o painel da secretaria em tempo real. */
    protected static function booted(): void
    {
        static::deleted(fn (Documento $d) => Storage::disk('local')->delete($d->caminho));
        static::saved(fn () => DocumentosMudaram::avisar());
        static::deleted(fn () => DocumentosMudaram::avisar());
    }
}
