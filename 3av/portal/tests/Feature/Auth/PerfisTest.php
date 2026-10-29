<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PerfisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('aluno');
        Role::findOrCreate('secretaria');
    }

    private function aluno(): User
    {
        return User::factory()->create(['matricula' => '202310042'])->assignRole('aluno');
    }

    private function secretaria(): User
    {
        return User::factory()->create(['email' => 'secretaria@iuva.edu.br'])->assignRole('secretaria');
    }

    public function test_aluno_entra_com_a_matricula_e_vai_para_a_area_dele(): void
    {
        $this->aluno();

        $this->post(route('login.store'), ['login' => '202310042', 'password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->get(route('dashboard'))->assertRedirect(route('aluno.inicio'));
    }

    public function test_secretaria_entra_com_o_email_e_vai_para_o_painel(): void
    {
        $this->secretaria();

        $this->post(route('login.store'), ['login' => 'secretaria@iuva.edu.br', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->get(route('dashboard'))->assertRedirect(route('secretaria.inicio'));
    }

    public function test_matricula_com_senha_errada_nao_entra(): void
    {
        $this->aluno();

        $this->post(route('login.store'), ['login' => '202310042', 'password' => 'errada'])
            ->assertSessionHasErrorsIn('login');

        $this->assertGuest();
    }

    public function test_matricula_que_nao_existe_nao_entra(): void
    {
        $this->post(route('login.store'), ['login' => '209999999', 'password' => 'password'])
            ->assertSessionHasErrorsIn('login');

        $this->assertGuest();
    }

    public function test_aluno_nao_abre_a_area_da_secretaria(): void
    {
        $this->actingAs($this->aluno())->get(route('secretaria.inicio'))->assertForbidden();
    }

    public function test_secretaria_nao_abre_a_area_do_aluno(): void
    {
        $this->actingAs($this->secretaria())->get(route('aluno.inicio'))->assertForbidden();
    }

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get(route('aluno.inicio'))->assertRedirect(route('login'));
        $this->get(route('secretaria.inicio'))->assertRedirect(route('login'));
    }

    public function test_cadastro_publico_esta_desligado(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
