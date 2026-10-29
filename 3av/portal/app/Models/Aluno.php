<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['user_id', 'curso_id', 'periodo_atual', 'turno', 'ingresso', 'situacao_matricula', 'data_nascimento'])]
class Aluno extends Model
{
    protected function casts(): array
    {
        return ['data_nascimento' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function cursadas(): HasMany
    {
        return $this->hasMany(Cursada::class);
    }

    /**
     * Resumo acadêmico que vai para a IA junto com a pergunta do chat.
     * Sem nome, matrícula, e-mail ou nascimento: só o necessário para responder sobre a situação dele.
     *
     * @return array<string, mixed>
     */
    public function resumoParaIa(): array
    {
        $this->loadMissing(['curso.disciplinas', 'cursadas.disciplina', 'cursadas.notas']);

        return [
            'curso' => $this->curso->nome,
            'periodo_atual' => $this->periodo_atual,
            'semestre_atual' => $this->cursadas->whereNull('situacao_final')->max('semestre') ?? $this->ingresso,
            // do curso, para a IA calcular o progresso (carga horária cumprida, prazo máximo, TCC...)
            'ingresso' => $this->ingresso,
            'duracao_semestres' => $this->curso->semestres,
            'total_disciplinas' => $this->curso->disciplinas->count(),
            'carga_horaria_total' => $this->curso->disciplinas->sum('carga_horaria'),
            'disciplinas' => $this->cursadas->map(fn (Cursada $c) => [
                'codigo' => $c->disciplina->codigo,
                'nome' => $c->disciplina->nome,
                'semestre' => $c->semestre,
                'carga_horaria' => $c->disciplina->carga_horaria,
                'total_avaliacoes' => $c->disciplina->avaliacoes,
                'notas' => $c->valoresDasNotas(),
                'prova_final' => $c->prova_final,
                'faltas' => $c->faltas,
                'situacao_final' => $c->situacao_final,
            ])->values()->all(),
        ];
    }

    /** Disciplinas do semestre em andamento (sem situação final registrada). */
    public function emCurso(): Collection
    {
        return $this->cursadas->whereNull('situacao_final')->sortBy('disciplina.nome')->values();
    }

    /** Histórico já fechado pela secretaria, do semestre mais recente para o mais antigo. */
    public function historico(): Collection
    {
        return $this->cursadas->whereNotNull('situacao_final')->sortByDesc('semestre')->groupBy('semestre');
    }
}
