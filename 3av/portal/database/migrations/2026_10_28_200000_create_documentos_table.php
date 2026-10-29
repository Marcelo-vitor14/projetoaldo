<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos que a IA consulta (Regimento, Manual...), enviados pela secretaria.
     * status: pendente (aguardando ingestão), processando, pronto ou erro.
     */
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('nome_arquivo');
            $table->string('caminho');
            $table->unsignedInteger('tamanho'); // bytes
            $table->string('status', 20)->default('pendente');
            $table->unsignedInteger('trechos')->nullable();
            $table->text('erro')->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
    }
};
