<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cursada_id', 'numero', 'valor'])]
class Nota extends Model
{
    protected function casts(): array
    {
        return ['valor' => 'float'];
    }

    public function cursada(): BelongsTo
    {
        return $this->belongsTo(Cursada::class);
    }
}
