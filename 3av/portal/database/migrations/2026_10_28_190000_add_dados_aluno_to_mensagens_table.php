<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disciplinas do aluno que a IA consultou para responder (o cartão "Consultei seus dados").
     */
    public function up(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->json('dados_aluno')->nullable()->after('calculo');
        });
    }

    public function down(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->dropColumn('dados_aluno');
        });
    }
};
