<?php

namespace App\Models;

use App\Events\DocumentosMudaram;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma versão da base do assistente. Só uma fica ativa; a anterior é guardada para poder voltar. */
#[Fillable(['versao', 'status', 'trechos', 'documentos', 'mudancas', 'erro', 'publicado_por'])]
class Publicacao extends Model
{
    protected $table = 'publicacoes';

    public const ROTULOS = ['montando' => 'Montando…', 'ativa' => 'Ativa', 'anterior' => 'Anterior', 'falhou' => 'Falhou', 'descartada' => 'Descartada'];

    public const CORES = ['montando' => 'blue', 'ativa' => 'green', 'anterior' => 'zinc', 'falhou' => 'red', 'descartada' => 'zinc'];

    protected function casts(): array
    {
        return ['documentos' => 'array', 'mudancas' => 'array'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }

    /** Avisa o painel da secretaria em tempo real a cada mudança de versão. */
    protected static function booted(): void
    {
        static::saved(fn () => DocumentosMudaram::avisar());
    }

    public static function ativa(): ?self
    {
        return static::where('status', 'ativa')->first();
    }

    public static function anterior(): ?self
    {
        return static::where('status', 'anterior')->first();
    }

    public static function emMontagem(): bool
    {
        return static::where('status', 'montando')->exists();
    }

    /** @return list<int> */
    public function idsDosDocumentos(): array
    {
        return array_column($this->documentos ?? [], 'id');
    }
}
