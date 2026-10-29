<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'titulo'])]
class Conversa extends Model
{
    use Prunable;

    /** Quantas mensagens anteriores vão para a IA, para ela entender o contexto. */
    public const CONTEXTO = 10;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(Mensagem::class)->orderBy('id');
    }

    public function perguntas(): int
    {
        return $this->mensagens->where('papel', 'user')->count();
    }

    public function travada(): bool
    {
        return $this->perguntas() >= config('uni-search.limite_perguntas');
    }

    /** Conversas paradas há mais tempo que o prazo do histórico. */
    public function scopeExpiradas(Builder $query): void
    {
        $query->where('updated_at', '<', now()->subDays(config('uni-search.historico_dias')));
    }

    /** Usado pelo `php artisan model:prune` (agendado todo dia). */
    public function prunable(): Builder
    {
        return static::query()->expiradas();
    }

    /** @return list<array{role: string, content: string}> as últimas mensagens, no formato da API */
    public function historicoParaIa(): array
    {
        return $this->mensagens()->reorder()->latest('id')->take(self::CONTEXTO)->get()->reverse()
            ->map(fn (Mensagem $m) => ['role' => $m->papel, 'content' => mb_substr($m->conteudo, 0, 2000)])
            ->values()->all();
    }
}
