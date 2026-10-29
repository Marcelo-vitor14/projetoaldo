<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico do chat por aluno. Apagado depois de config('uni-search.historico_dias') sem uso.
     */
    public function up(): void
    {
        Schema::create('conversas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('titulo', 80);
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained()->cascadeOnDelete();
            $table->string('papel', 10); // user ou assistant
            $table->text('conteudo');
            $table->json('fontes')->nullable();  // artigos citados (Art. 15 · p. 2)
            $table->json('calculo')->nullable(); // cartão da calculadora
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens');
        Schema::dropIfExists('conversas');
    }
};
