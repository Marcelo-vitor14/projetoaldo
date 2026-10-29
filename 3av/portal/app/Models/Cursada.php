<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['aluno_id', 'disciplina_id', 'semestre', 'prova_final', 'faltas', 'situacao_final'])]
class Cursada extends Model
{
    /** Nomes das situações devolvidas pela API (a regra em si fica na API, num lugar só). */
    public const ROTULOS = [
        'cursando' => 'Cursando',
        'aprovado_direto' => 'Aprovado',
        'prova_final' => 'Prova final',
        'aprovado_final' => 'Aprovado na final',
        'reprovado_final' => 'Reprovado na final',
        'reprovado_nota' => 'Reprovado por nota',
        'reprovado_falta' => 'Reprovado por falta',
    ];

    protected function casts(): array
    {
        return ['prova_final' => 'float'];
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    /** Notas das avaliações, na ordem (1ª, 2ª, 3ª...). */
    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class)->orderBy('numero');
    }

    /** Lança as notas na ordem, a partir da 1ª avaliação. */
    public function lancarNotas(array $valores): void
    {
        foreach (array_values($valores) as $i => $valor) {
            $this->notas()->create(['numero' => $i + 1, 'valor' => $valor]);
        }
    }

    /** @return list<float> */
    public function valoresDasNotas(): array
    {
        return $this->notas->pluck('valor')->all();
    }

    public static function rotulo(?string $situacao): string
    {
        return self::ROTULOS[$situacao] ?? '—';
    }
}
