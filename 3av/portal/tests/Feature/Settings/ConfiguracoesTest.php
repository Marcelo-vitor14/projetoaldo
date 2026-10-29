<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConfiguracoesTest extends TestCase
{
    use RefreshDatabase;

    private User $aluno;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('aluno');
        $this->aluno = User::factory()->create(['matricula' => '202310042', 'name' => 'Ana', 'email' => 'ana@aluno.iuva.edu.br'])->assignRole('aluno');
    }

    public function test_nao_ha_tela_para_mudar_nome_email_ou_excluir_a_conta(): void
    {
        $this->actingAs($this->aluno)->get('/settings/profile')->assertNotFound();
        $this->actingAs($this->aluno)->get('/settings')->assertRedirect('/settings/conta');
        $this->actingAs($this->aluno)->get(route('appearance.edit'))->assertOk()->assertDontSee('Perfil');
    }

    public function test_aba_conta_mostra_os_dados_de_acesso_e_o_botao_de_sair_sem_pedir_senha(): void
    {
        $this->actingAs($this->aluno)->get(route('conta.edit'))
            ->assertOk()
            ->assertSee('202310042')->assertSee('ana@aluno.iuva.edu.br')
            ->assertSee('Sair da conta')->assertSee('Meu perfil');
    }

    public function test_aluno_que_entrou_pela_matricula_consegue_confirmar_a_senha_para_a_seguranca(): void
    {
        $this->actingAs($this->aluno)->get(route('security.edit'))->assertRedirect(route('password.confirm'));

        $this->actingAs($this->aluno)->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->actingAs($this->aluno)->get(route('security.edit'))->assertOk();
    }

    public function test_senha_errada_nao_confirma(): void
    {
        $this->actingAs($this->aluno)->post(route('password.confirm.store'), ['password' => 'errada'])->assertSessionHasErrors('password');
        $this->actingAs($this->aluno)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    }
}
