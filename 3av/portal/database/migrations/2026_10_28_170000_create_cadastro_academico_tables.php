<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cadastro acadêmico: a conta de login fica em users; a ficha do aluno, em alunos.
     */
    public function up(): void
    {
        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('sigla', 10)->unique();
            $table->unsignedTinyInteger('semestres');
            $table->string('turno', 20);
            $table->timestamps();
        });

        Schema::create('disciplinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->constrained()->cascadeOnDelete();
            $table->string('codigo', 10)->unique();
            $table->string('nome');
            $table->unsignedSmallInteger('carga_horaria');
            $table->unsignedTinyInteger('periodo');
            $table->unsignedTinyInteger('avaliacoes')->default(2); // quantas notas a disciplina tem
            $table->timestamps();
        });

        Schema::create('alunos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('curso_id')->constrained();
            $table->unsignedTinyInteger('periodo_atual');
            $table->string('turno', 20);
            $table->string('ingresso', 6); // ex: 2023.1
            $table->string('situacao_matricula', 20)->default('ativa'); // ativa, trancada
            $table->date('data_nascimento');
            $table->timestamps();
        });

        // Uma linha por disciplina cursada em um semestre. situacao_final vazia = ainda em curso.
        Schema::create('cursadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aluno_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disciplina_id')->constrained();
            $table->string('semestre', 6);
            $table->decimal('prova_final', 3, 1)->nullable();
            $table->unsignedSmallInteger('faltas')->default(0);
            $table->string('situacao_final', 20)->nullable();
            $table->timestamps();
            $table->unique(['aluno_id', 'disciplina_id', 'semestre']);
        });

        // Uma linha por avaliação (1ª, 2ª, 3ª...): a média é a média simples de todas
        Schema::create('notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cursada_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('numero');
            $table->decimal('valor', 3, 1);
            $table->timestamps();
            $table->unique(['cursada_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas');
        Schema::dropIfExists('cursadas');
        Schema::dropIfExists('alunos');
        Schema::dropIfExists('disciplinas');
        Schema::dropIfExists('cursos');
    }
};
