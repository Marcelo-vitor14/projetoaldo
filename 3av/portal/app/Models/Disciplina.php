<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['curso_id', 'codigo', 'nome', 'carga_horaria', 'periodo', 'avaliacoes'])]
class Disciplina extends Model
{
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }
}
