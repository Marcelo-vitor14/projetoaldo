<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversa_id', 'papel', 'conteudo', 'fontes', 'calculo', 'dados_aluno'])]
class Mensagem extends Model
{
    protected $table = 'mensagens';

    protected function casts(): array
    {
        return ['fontes' => 'array', 'calculo' => 'array', 'dados_aluno' => 'array'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(Conversa::class);
    }

    public function doAluno(): bool
    {
        return $this->papel === 'user';
    }
}
