<?php

namespace Database\Seeders;

use App\Models\Aluno;
use App\Models\Curso;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AlunosSeeder extends Seeder
{
    /** Semestre em andamento: as disciplinas dele ficam sem situação final (a API calcula). */
    public const SEMESTRE_ATUAL = '2026.2';

    private Curso $curso;

    /**
     * Cinco alunos montados à mão, um por cenário, e vinte gerados para a lista da secretaria.
     * Tudo fictício e reproduzível (mesma semente toda vez).
     */
    public function run(): void
    {
        mt_srand(2026);
        fake()->seed(2026);
        $this->curso = Curso::where('sigla', 'SI')->firstOrFail();

        // Prova final em Sistemas de Apoio à Decisão (5,5 e 6: o exemplo da apresentação) e uma reprovação antiga em Cálculo I
        $this->criar('Ana Beatriz Souza', '202310042', '2001-03-14',
            atual: ['SAD' => [[5.5, 6.0], 4]],
            reprovacoes: ['CAL' => [3.5, 4.0]]);

        // Reprovado por falta em Sistemas Distribuídos (18 faltas; o limite em 60 h é 15)
        $this->criar('Bruno Henrique Lima', '202410015', '2003-07-22',
            atual: ['SDI' => [[7.5, 8.0], 18]]);

        // Reprovado por nota em Pesquisa Operacional (média 3,75)
        $this->criar('Carla Mendes Rocha', '202420007', '2004-11-02',
            atual: ['POP' => [[3.0, 4.5], 6]]);

        // Aprovado direto em tudo
        $this->criar('Diego Alves Pereira', '202510088', '2006-01-30',
            atual: ['BD2' => [[9.5, 9.0, 8.5], 0], 'ESW' => [[8.5, 9.0], 2], 'RED' => [[9.0, 8.5, 9.5], 4], 'WEB' => [[10.0, 9.5, 9.0], 0], 'IHC' => [[8.0, 8.5], 2]]);

        // Quase no limite de faltas em Banco de Dados I (19 de 20 em 80 h)
        $this->criar('Elisa Fernandes Costa', '202520031', '2005-05-09',
            atual: ['BD1' => [[7.0, 7.5, 8.0], 19]]);

        $ingressos = ['2023.1', '2023.2', '2024.1', '2024.2', '2025.1', '2025.2', '2026.1', '2026.2'];
        for ($i = 1; $i <= 20; $i++) {
            $ingresso = $ingressos[array_rand($ingressos)];
            $matricula = str_replace('.', '', $ingresso).str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT);
            $this->criar(fake()->name(), $matricula, fake()->dateTimeBetween('-30 years', '-18 years')->format('Y-m-d'));
        }
    }

    /**
     * @param  array<string, array{list<float>, int}>  $atual  notas e faltas fixas no semestre atual: código => [[notas...], faltas]
     * @param  array<string, list<float>>  $reprovacoes  reprovações por nota no histórico (refeitas e aprovadas no semestre seguinte)
     */
    private function criar(string $nome, string $matricula, string $nascimento, array $atual = [], array $reprovacoes = []): void
    {
        $ingresso = substr($matricula, 0, 4).'.'.substr($matricula, 4, 1);
        $semestres = $this->semestresDesde($ingresso);
        $periodo = count($semestres);

        $user = User::factory()->create([
            'name' => $nome,
            'matricula' => $matricula,
            'email' => Str::slug(Str::before($nome, ' ').' '.Str::afterLast($nome, ' '), '.').'.'.substr($matricula, -4).'@aluno.iuva.edu.br',
        ])->assignRole('aluno');

        $aluno = Aluno::create([
            'user_id' => $user->id, 'curso_id' => $this->curso->id, 'periodo_atual' => $periodo, 'turno' => 'Noturno',
            'ingresso' => $ingresso, 'situacao_matricula' => 'ativa', 'data_nascimento' => $nascimento,
        ]);

        foreach ($this->curso->disciplinas as $disciplina) {
            if ($disciplina->periodo > $periodo) {
                continue;
            }
            $semestre = $semestres[$disciplina->periodo - 1];
            $limite = intdiv($disciplina->carga_horaria, 4);

            if ($disciplina->periodo === $periodo) { // semestre em andamento
                [$notas, $faltas] = $atual[$disciplina->codigo] ?? [$this->notas($disciplina->avaliacoes, 5.0), mt_rand(0, $limite - 4)];
                $aluno->cursadas()->create(['disciplina_id' => $disciplina->id, 'semestre' => $semestre, 'faltas' => $faltas])->lancarNotas($notas);

                continue;
            }

            $aprovacao = $semestre;
            if (isset($reprovacoes[$disciplina->codigo])) {
                $aluno->cursadas()->create(['disciplina_id' => $disciplina->id, 'semestre' => $semestre,
                    'faltas' => mt_rand(2, $limite - 2), 'situacao_final' => 'reprovado_nota'])->lancarNotas($reprovacoes[$disciplina->codigo]);
                $aprovacao = $semestres[$disciplina->periodo];
            }
            // Histórico fechado: aprovado direto (média 7 ou mais)
            $aluno->cursadas()->create(['disciplina_id' => $disciplina->id, 'semestre' => $aprovacao,
                'faltas' => mt_rand(0, $limite - 4), 'situacao_final' => 'aprovado_direto'])->lancarNotas($this->notas($disciplina->avaliacoes, 7.0));
        }
    }

    /** @return list<string> do semestre de ingresso até o atual, ex: ['2025.1', '2025.2', '2026.1', '2026.2'] */
    private function semestresDesde(string $ingresso): array
    {
        [$ano, $sem] = array_map('intval', explode('.', $ingresso));
        $lista = [];
        while (true) {
            $lista[] = "$ano.$sem";
            if ("$ano.$sem" === self::SEMESTRE_ATUAL) {
                return $lista;
            }
            [$ano, $sem] = $sem === 1 ? [$ano, 2] : [$ano + 1, 1];
        }
    }

    /** @return list<float> notas aleatórias com uma casa decimal, de $minima até 10 */
    private function notas(int $quantidade, float $minima): array
    {
        return array_map(fn () => mt_rand((int) ($minima * 10), 100) / 10, range(1, $quantidade));
    }
}
