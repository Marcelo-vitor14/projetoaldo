<?php

namespace Database\Seeders;

use App\Models\Curso;
use Illuminate\Database\Seeder;

class CursoSeeder extends Seeder
{
    /**
     * Sistemas de Informação do IUVA (fictício): 8 períodos, 5 disciplinas por período.
     */
    public function run(): void
    {
        $curso = Curso::create(['nome' => 'Sistemas de Informação', 'sigla' => 'SI', 'semestres' => 8, 'turno' => 'Noturno']);

        // [período, código, nome, carga horária]
        $grade = [
            [1, 'ALG', 'Algoritmos e Lógica de Programação', 80],
            [1, 'FSI', 'Fundamentos de Sistemas de Informação', 60],
            [1, 'MDI', 'Matemática Discreta', 60],
            [1, 'ING', 'Inglês Instrumental', 60],
            [1, 'CEX', 'Comunicação e Expressão', 60],
            [2, 'POO', 'Programação Orientada a Objetos', 80],
            [2, 'CAL', 'Cálculo I', 60],
            [2, 'ARQ', 'Arquitetura de Computadores', 60],
            [2, 'TGA', 'Teoria Geral da Administração', 60],
            [2, 'EST', 'Estatística', 60],
            [3, 'EDD', 'Estrutura de Dados', 80],
            [3, 'BD1', 'Banco de Dados I', 80],
            [3, 'SOP', 'Sistemas Operacionais', 60],
            [3, 'ERQ', 'Engenharia de Requisitos', 60],
            [3, 'CTG', 'Contabilidade Gerencial', 60],
            [4, 'BD2', 'Banco de Dados II', 80],
            [4, 'ESW', 'Engenharia de Software', 60],
            [4, 'RED', 'Redes de Computadores', 80],
            [4, 'WEB', 'Programação Web', 80],
            [4, 'IHC', 'Interação Humano-Computador', 60],
            [5, 'APS', 'Análise e Projeto de Sistemas', 60],
            [5, 'MOB', 'Desenvolvimento Mobile', 80],
            [5, 'SEG', 'Segurança da Informação', 60],
            [5, 'GPR', 'Gestão de Projetos', 60],
            [5, 'POP', 'Pesquisa Operacional', 60],
            [6, 'SIA', 'Sistemas Inteligentes Aplicados', 80],
            [6, 'SDI', 'Sistemas Distribuídos', 60],
            [6, 'GTI', 'Governança de TI', 60],
            [6, 'EMP', 'Empreendedorismo', 60],
            [6, 'QSW', 'Qualidade de Software', 60],
            [7, 'BIN', 'Inteligência de Negócios', 60],
            [7, 'NUV', 'Computação em Nuvem', 60],
            [7, 'ELT', 'Ética e Legislação em TI', 60],
            [7, 'TC1', 'Trabalho de Conclusão de Curso I', 60],
            [7, 'TAD', 'Tópicos Avançados em Dados', 60],
            [8, 'TC2', 'Trabalho de Conclusão de Curso II', 80],
            [8, 'GCO', 'Gestão do Conhecimento', 60],
            [8, 'AUD', 'Auditoria de Sistemas', 60],
            [8, 'SAD', 'Sistemas de Apoio à Decisão', 60],
            [8, 'EGI', 'Estágio Supervisionado', 60],
        ];

        foreach ($grade as [$periodo, $codigo, $nome, $carga]) {
            // 80 h: três avaliações; TCC: só a nota da banca; as demais: duas
            $avaliacoes = str_starts_with($codigo, 'TC') ? 1 : ($carga >= 80 ? 3 : 2);
            $curso->disciplinas()->create(['periodo' => $periodo, 'codigo' => $codigo, 'nome' => $nome, 'carga_horaria' => $carga, 'avaliacoes' => $avaliacoes]);
        }
    }
}
