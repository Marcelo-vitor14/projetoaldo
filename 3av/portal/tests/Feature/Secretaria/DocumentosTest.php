<?php

namespace Tests\Feature\Secretaria;

use App\Jobs\ConferirDocumento;
use App\Jobs\PublicarBase;
use App\Models\Documento;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\IaApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentosTest extends TestCase
{
    use RefreshDatabase;

    private User $secretaria;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Role::findOrCreate('secretaria');
        Role::findOrCreate('aluno');
        $this->secretaria = User::factory()->create(['email' => 'sec.teste@iuva.edu.br'])->assignRole('secretaria');
    }

    private function documento(string $nome, string $status, array $campos = []): Documento
    {
        $caminho = 'documentos/'.str($nome)->slug().'.pdf';
        Storage::disk('local')->put($caminho, '%PDF-1.4 '.$nome);
        $doc = Documento::create(array_merge(['nome' => $nome, 'nome_arquivo' => basename($caminho), 'caminho' => $caminho,
            'tamanho' => 20, 'status' => $status, 'trechos' => 10], $campos));
        $doc->update(['chave_ia' => $campos['chave_ia'] ?? 'portal-'.$doc->id]);

        return $doc;
    }

    /** Base publicada (versão 1) com o Regimento 2026. */
    private function baseInicial(): Documento
    {
        $regimento = $this->documento('Regimento 2026', 'publicado', ['trechos' => 48]);
        Publicacao::create(['versao' => 1, 'status' => 'ativa', 'trechos' => 48, 'documentos' => [['id' => $regimento->id, 'nome' => $regimento->nome]]]);

        return $regimento;
    }

    private function tela()
    {
        return Livewire::actingAs($this->secretaria)->test('pages::secretaria.documentos');
    }

    public function test_painel_abre_para_a_secretaria_e_nao_para_o_aluno(): void
    {
        $this->actingAs($this->secretaria)->get(route('secretaria.inicio'))->assertOk()->assertSee('Base do assistente');

        $aluno = User::factory()->create()->assignRole('aluno');
        $this->actingAs($aluno)->get(route('secretaria.inicio'))->assertForbidden();
    }

    public function test_enviar_so_entra_no_rascunho_e_manda_conferir(): void
    {
        Queue::fake();
        $this->baseInicial();

        $this->tela()
            ->set('arquivo', UploadedFile::fake()->create('Edital_Monitoria_2027.pdf', 300, 'application/pdf'))
            ->assertSet('nome', 'Edital Monitoria 2027')
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertSee('Conferindo…');

        $doc = Documento::where('nome', 'Edital Monitoria 2027')->firstOrFail();
        $this->assertSame(['conferindo', 'portal-'.$doc->id], [$doc->status, $doc->chave_ia]);
        Queue::assertPushed(ConferirDocumento::class);
        Queue::assertNotPushed(PublicarBase::class); // a base não muda sozinha
        $this->assertSame('ativa', Publicacao::firstWhere('versao', 1)->status);
    }

    public function test_recusa_arquivo_que_nao_e_pdf_e_pdf_acima_de_20_mb(): void
    {
        $tela = $this->tela();
        $tela->set('arquivo', UploadedFile::fake()->create('foto.png', 100, 'image/png'))->call('enviar')->assertHasErrors(['arquivo' => 'mimes']);
        $tela->set('arquivo', UploadedFile::fake()->create('grande.pdf', 20481, 'application/pdf'))->call('enviar')->assertHasErrors('arquivo'); // barrado já no upload

        $this->assertSame(0, Documento::count());
    }

    public function test_conferencia_ok_vira_vai_entrar_e_pdf_ruim_vira_erro(): void
    {
        $bom = $this->documento('Bom', 'conferindo');
        $ruim = $this->documento('Ruim', 'conferindo');
        Http::fake(function (Request $r) {
            return str_contains($r->body(), 'Ruim')
                ? Http::response(['detail' => 'O PDF não tem texto (parece escaneado).'], 422)
                : Http::response(['trechos' => 12, 'com_artigos' => false]);
        });

        (new ConferirDocumento($bom))->handle(app(IaApi::class));
        (new ConferirDocumento($ruim))->handle(app(IaApi::class));

        $this->assertSame(['a_entrar', 12], [$bom->fresh()->status, $bom->fresh()->trechos]);
        $this->assertSame('invalido', $ruim->fresh()->status);
        $this->assertStringContainsString('escaneado', $ruim->fresh()->erro);
    }

    public function test_tirar_e_manter_so_mudam_o_rascunho(): void
    {
        $regimento = $this->baseInicial();

        $this->tela()->call('tirar', $regimento->id)->assertSee('Vai sair')->assertSee('− Regimento 2026');
        $this->assertSame('a_sair', $regimento->fresh()->status);

        $this->tela()->call('manter', $regimento->id);
        $this->assertSame('publicado', $regimento->fresh()->status);
    }

    public function test_cancelar_envio_apaga_e_devolve_o_substituido(): void
    {
        $regimento = $this->baseInicial();
        $novo = $this->documento('Regimento 2027', 'a_entrar', ['substitui_id' => $regimento->id]);
        $regimento->update(['status' => 'a_sair']);

        $this->tela()->call('cancelar', $novo->id);

        $this->assertModelMissing($novo);
        Storage::disk('local')->assertMissing($novo->caminho);
        $this->assertSame('publicado', $regimento->fresh()->status);
    }

    public function test_botao_fica_travado_com_conferencia_erro_ou_base_vazia(): void
    {
        Queue::fake();
        $regimento = $this->baseInicial();

        $this->tela()->assertSee('Nenhuma alteração pendente.')->call('atualizarBase');
        $conferindo = $this->documento('Edital', 'conferindo');
        $this->tela()->assertSee('Espere a conferência')->call('atualizarBase');
        $conferindo->update(['status' => 'invalido']);
        $this->tela()->assertSee('Há documento com erro')->call('atualizarBase');
        $conferindo->delete();
        $regimento->update(['status' => 'a_sair']);
        $this->tela()->assertSee('não pode ficar sem nenhum documento')->call('atualizarBase');

        Queue::assertNotPushed(PublicarBase::class);
        $this->assertSame(1, Publicacao::count());
    }

    public function test_atualizar_cria_a_versao_seguinte_e_manda_montar(): void
    {
        Queue::fake();
        $this->baseInicial();
        $this->documento('Edital 2027', 'a_entrar');

        $this->tela()->call('atualizarBase')->assertSee('Montando a nova versão');

        $p = Publicacao::firstWhere('versao', 2);
        $this->assertSame(['montando', ['entram' => ['Edital 2027'], 'saem' => []]], [$p->status, $p->mudancas]);
        Queue::assertPushed(PublicarBase::class);
        $this->tela()->call('atualizarBase'); // segunda vez: já há uma em andamento
        $this->assertSame(2, Publicacao::count());
    }

    public function test_publicacao_com_sucesso_troca_a_versao_e_aplica_o_rascunho(): void
    {
        $regimento = $this->baseInicial();
        $novo = $this->documento('Regimento 2027', 'a_entrar', ['substitui_id' => $regimento->id]);
        $regimento->update(['status' => 'a_sair']);
        $p = Publicacao::create(['versao' => 2, 'status' => 'montando']);
        Http::fake(['*/api/v1/base' => Http::response(['versao' => 2, 'trechos' => 50, 'documentos' => [['documento_id' => $novo->chave_ia, 'trechos' => 50]]])]);

        (new PublicarBase($p))->handle(app(IaApi::class));

        $this->assertSame(['ativa', 50], [$p->fresh()->status, $p->fresh()->trechos]);
        $this->assertSame('anterior', Publicacao::firstWhere('versao', 1)->status);
        $this->assertSame(['publicado', 50], [$novo->fresh()->status, $novo->fresh()->trechos]);
        $this->assertSame('removido', $regimento->fresh()->status); // guardado para poder voltar
        Storage::disk('local')->assertExists($regimento->caminho);

        Http::assertSent(function (Request $r) use ($novo) {
            $docs = json_decode(collect($r->data())->firstWhere('name', 'documentos')['contents'], true);

            return $docs === [['documento_id' => $novo->chave_ia, 'nome' => 'Regimento 2027']]; // só a lista final, do zero
        });
    }

    public function test_publicacao_que_falha_nao_muda_nada(): void
    {
        $regimento = $this->baseInicial();
        $edital = $this->documento('Edital', 'a_entrar');
        $p = Publicacao::create(['versao' => 2, 'status' => 'montando']);
        Http::fake(['*' => Http::response(['detail' => 'Edital: o PDF não tem texto (parece escaneado).'], 422)]);

        (new PublicarBase($p))->handle(app(IaApi::class));

        $this->assertSame('falhou', $p->fresh()->status);
        $this->assertSame('ativa', Publicacao::firstWhere('versao', 1)->status);
        $this->assertSame(['publicado', 'a_entrar'], [$regimento->fresh()->status, $edital->fresh()->status]);
        $this->tela()->assertSee('A versão 2 não foi publicada.')->assertSee('escaneado');
    }

    public function test_ia_fora_do_ar_tenta_de_novo_e_no_fim_marca_falha(): void
    {
        $this->baseInicial();
        $this->documento('Edital', 'a_entrar');
        $p = Publicacao::create(['versao' => 2, 'status' => 'montando']);
        Http::fake(fn () => throw new ConnectionException('fora'));
        $job = new PublicarBase($p);

        try {
            $job->handle(app(IaApi::class));
            $this->fail('Deveria pedir nova tentativa');
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        $this->assertSame('falhou', $p->fresh()->status);
        $this->assertStringContainsString('versão atual continua valendo', $p->fresh()->erro);
    }

    public function test_voltar_restaura_a_anterior_e_as_mudancas_ficam_pendentes(): void
    {
        $regimento = $this->documento('Regimento 2026', 'removido', ['trechos' => 48]);
        $novo = $this->documento('Regimento 2027', 'publicado', ['trechos' => 50]);
        Publicacao::create(['versao' => 1, 'status' => 'anterior', 'documentos' => [['id' => $regimento->id, 'nome' => 'Regimento 2026']]]);
        Publicacao::create(['versao' => 2, 'status' => 'ativa', 'documentos' => [['id' => $novo->id, 'nome' => 'Regimento 2027']]]);
        Http::fake(['*/api/v1/base/voltar' => Http::response(['versao' => 1, 'ativa' => 'regimento_academico'])]);

        $this->tela()->call('voltar')->assertSee('Versão 1');

        $this->assertSame(['ativa', 'descartada'], [Publicacao::firstWhere('versao', 1)->status, Publicacao::firstWhere('versao', 2)->status]);
        $this->assertSame(['publicado', 'a_entrar'], [$regimento->fresh()->status, $novo->fresh()->status]);
    }

    public function test_seeder_publica_a_versao_1_com_os_dois_pdfs(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $v1 = Publicacao::ativa();
        $this->assertSame([1, 63], [$v1->versao, $v1->trechos]);
        $this->assertSame(['Manual do Aluno IUVA 2026' => 15, 'Regimento Acadêmico IUVA 2026' => 48],
            Documento::where('status', 'publicado')->orderBy('nome')->pluck('trechos', 'nome')->all());
    }
}
