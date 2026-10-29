<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nome', 'sigla', 'semestres', 'turno'])]
class Curso extends Model
{
    public function disciplinas(): HasMany
    {
        return $this->hasMany(Disciplina::class);
    }
}
