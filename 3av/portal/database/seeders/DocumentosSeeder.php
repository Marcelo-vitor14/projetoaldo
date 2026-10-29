<?php

namespace Database\Seeders;

use App\Models\Documento;
use App\Models\Publicacao;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DocumentosSeeder extends Seeder
{
    /**
     * Os dois PDFs que a IA já usa (pasta ia/data/raw), registrados como prontos.
     * Os trechos são os que a ingestão gerou no ChromaDB (63 no total).
     */
    public function run(): void
    {
        $pasta = base_path('../ia/data/raw');
        $secretaria = User::where('email', 'secretaria@iuva.edu.br')->first();

        $publicados = [];
        foreach ([
            ['Regimento Acadêmico IUVA 2026', 'Regimento_Academico_IUVA_2026.pdf', 48],
            ['Manual do Aluno IUVA 2026', 'Manual_do_Aluno_IUVA_2026.pdf', 15],
        ] as [$nome, $arquivo, $trechos]) {
            if (! is_file("$pasta/$arquivo")) {
                continue; // portal rodando sem a pasta da IA ao lado
            }
            $caminho = "documentos/$arquivo";
            Storage::disk('local')->put($caminho, file_get_contents("$pasta/$arquivo"));

            $publicados[] = Documento::create([
                // a ingestão pelo terminal usa o nome do arquivo como documento_id na IA
                'nome' => $nome, 'nome_arquivo' => $arquivo, 'caminho' => $caminho, 'chave_ia' => $arquivo,
                'tamanho' => filesize("$pasta/$arquivo"), 'status' => 'publicado', 'trechos' => $trechos,
                'enviado_por' => $secretaria?->id,
            ]);
        }

        // Versão 1 da base: a coleção que a ingestão do MVP criou na IA
        if ($publicados) {
            Publicacao::create([
                'versao' => 1, 'status' => 'ativa', 'trechos' => 63, 'publicado_por' => $secretaria?->id,
                'documentos' => array_map(fn ($d) => ['id' => $d->id, 'nome' => $d->nome], $publicados),
                'mudancas' => ['entram' => array_map(fn ($d) => $d->nome, $publicados), 'saem' => []],
            ]);
        }
    }
}
