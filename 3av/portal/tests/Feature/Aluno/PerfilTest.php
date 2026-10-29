<?php

namespace Tests\Feature\Aluno;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Semeia dentro da transação do teste, para não sobrar dado para os outros testes
        $this->seed(DatabaseSeeder::class);
    }

    private function ana(): User
    {
        return User::where('matricula', '202310042')->firstOrFail();
    }

    public function test_seeder_cria_os_cenarios_de_teste(): void
    {
        $this->assertSame(25, User::role('aluno')->count());
        $ana = $this->ana()->aluno;

        $this->assertSame(8, $ana->periodo_atual);
        $this->assertCount(5, $ana->emCurso());
        $this->assertSame(1, $ana->cursadas->where('situacao_final', 'reprovado_nota')->count());
        $this->assertNull(User::where('email', 'secretaria@iuva.edu.br')->first()->aluno);
    }

    public function test_perfil_mostra_a_situacao_calculada_pela_api(): void
    {
        Http::fake(['*/api/v1/situacao-academica' => function (Request $request) {
            return Http::response(['disciplinas' => collect($request['disciplinas'])->map(fn ($d) => [
                'codigo' => $d['codigo'],
                'situacao' => $d['codigo'] === 'SAD' ? 'prova_final' : 'aprovado_direto',
                'media_parcial' => $d['codigo'] === 'SAD' ? 5.75 : 8.0,
                'nota_necessaria_final' => $d['codigo'] === 'SAD' ? 6.25 : null,
                'media_final' => null,
                'limite_faltas' => intdiv($d['carga_horaria'], 4),
                'faltas_restantes' => 10,
            ])->all()]);
        }]);

        $this->actingAs($this->ana())->get(route('aluno.perfil'))
            ->assertOk()
            ->assertSee('Ana Beatriz Souza')
            ->assertSee('202310042')
            ->assertSee('Sistemas de Apoio à Decisão')
            ->assertSee('Na final')->assertSee('6,25')
            ->assertSee('Reprovado por nota') // Cálculo I, no histórico
            ->assertDontSee('indisponíveis');

        // O portal manda só notas, faltas e carga horária: nada de nome, e-mail ou matrícula
        Http::assertSent(fn (Request $r) => ! str_contains($r->body(), 'Ana') && ! str_contains($r->body(), '202310042'));
    }

    public function test_disciplina_com_tres_avaliacoes_manda_as_tres_notas(): void
    {
        Http::fake(['*/api/v1/situacao-academica' => Http::response(['disciplinas' => []])]);
        $diego = User::where('matricula', '202510088')->firstOrFail();

        $this->actingAs($diego)->get(route('aluno.perfil'))
            ->assertOk()
            ->assertSee('9,5 · 9,0 · 8,5'); // Banco de Dados II (80 h)

        Http::assertSent(function (Request $r) {
            $bd2 = collect($r['disciplinas'])->firstWhere('codigo', 'BD2');

            return $bd2['total_avaliacoes'] === 3 && $bd2['notas'] === [9.5, 9.0, 8.5];
        });
    }

    public function test_perfil_continua_abrindo_com_a_api_fora_do_ar(): void
    {
        Http::fake(fn () => throw new ConnectionException('fora do ar'));

        $this->actingAs($this->ana())->get(route('aluno.perfil'))
            ->assertOk()
            ->assertSee('Média e situação indisponíveis no momento')
            ->assertSee('Sistemas de Apoio à Decisão');
    }

    public function test_secretaria_nao_tem_perfil_de_aluno(): void
    {
        $secretaria = User::where('email', 'secretaria@iuva.edu.br')->first();

        $this->actingAs($secretaria)->get(route('aluno.perfil'))->assertForbidden();
    }
}
