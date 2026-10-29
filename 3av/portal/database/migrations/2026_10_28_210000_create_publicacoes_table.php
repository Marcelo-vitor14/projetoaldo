<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rascunho e publicação: mexer nos documentos não muda a IA na hora; o botão "Atualizar a base"
     * monta uma versão nova inteira. Cada versão publicada (ou tentada) fica em publicacoes.
     */
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $table) {
            // chave_ia: como a IA identifica o documento nos trechos (documento_id no ChromaDB)
            $table->string('chave_ia', 64)->nullable()->unique()->after('caminho');
            // substitui_id: o documento que sai quando este entrar
            $table->foreignId('substitui_id')->nullable()->after('chave_ia')->constrained('documentos')->nullOnDelete();
        });

        Schema::create('publicacoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('versao')->unique();
            $table->string('status', 20); // montando, ativa, anterior, falhou, descartada
            $table->unsignedInteger('trechos')->nullable();
            $table->json('documentos')->nullable(); // [{id, nome}] que estão nesta versão
            $table->json('mudancas')->nullable();   // {entram: [...], saem: [...]}
            $table->text('erro')->nullable();
            $table->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicacoes');
        Schema::table('documentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitui_id');
            $table->dropColumn('chave_ia');
        });
    }
};
