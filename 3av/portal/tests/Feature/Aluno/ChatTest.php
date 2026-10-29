<?php

namespace Tests\Feature\Aluno;

use App\Models\Conversa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $aluno;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ia.token' => 'token-de-teste']);
        Role::findOrCreate('aluno');
        $this->aluno = User::factory()->create(['matricula' => '202399999'])->assignRole('aluno');
        // O streaming do Livewire imprime os pedaços e esvazia o buffer (ob_flush) a cada um:
        // um buffer com callback recolhe tudo, para conferir e para não sujar o relatório
        ob_start(function (string $pedaco) {
            $this->transmitido .= $pedaco;

            return '';
        });
    }

    private string $transmitido = '';

    protected function tearDown(): void
    {
        ob_end_clean();
        parent::tearDown();
    }

    /** Resposta da IA no formato aos poucos (uma linha JSON por pedaço). */
    public static function ndjson(string $texto, array $extra = []): string
    {
        $pedacos = collect(explode(' ', $texto))->map(fn ($p, $i) => ['tipo' => 'texto', 'texto' => ($i ? ' ' : '').$p]);
        $fim = ['tipo' => 'fim', 'response' => $texto, 'sources' => [], 'tool_called' => null, 'calculo' => null, 'dados_aluno' => [], ...$extra];

        return $pedacos->push($fim)->map(fn ($e) => json_encode($e))->join("\n")."\n";
    }

    private function iaResponde(string $texto = 'Você pode ter até 15 horas de faltas.'): void
    {
        Http::fake(['*/api/v1/chat/aos-poucos' => Http::response(self::ndjson($texto, [
            'sources' => [['documento' => 'Regimento_Academico_IUVA_2026.pdf', 'artigo' => 'Art. 15', 'pagina' => 2, 'trecho' => 'O limite de faltas corresponde a 25%...']],
        ]))]);
    }

    public function test_chat_abre_para_o_aluno(): void
    {
        $this->actingAs($this->aluno)->get(route('aluno.inicio'))->assertOk()->assertSee('Nova conversa');
    }

    public function test_pergunta_vira_conversa_com_a_resposta_e_a_fonte(): void
    {
        $this->iaResponde();

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Quantas faltas posso ter em 60 horas?')
            ->call('enviar')
            ->assertSet('aguardando', true)
            ->call('responder')
            ->assertSet('aguardando', false)
            ->assertSee('Você pode ter até 15 horas de faltas.')
            ->assertSee('Art. 15')->assertSee('p. 2');

        $conversa = $this->aluno->conversas()->first();
        $this->assertSame('Quantas faltas posso ter em 60 horas?', $conversa->titulo);
        $this->assertSame(['user', 'assistant'], $conversa->mensagens->pluck('papel')->all());

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-IA-Token', 'token-de-teste')
            && $r['messages'] === [['role' => 'user', 'content' => 'Quantas faltas posso ter em 60 horas?']]);
    }

    public function test_a_ia_recebe_o_historico_da_conversa(): void
    {
        $this->iaResponde();
        $chat = Livewire::actingAs($this->aluno)->test('pages::aluno.chat');
        $chat->set('pergunta', 'Primeira pergunta')->call('enviar')->call('responder');
        $chat->set('pergunta', 'E a segunda?')->call('enviar')->call('responder');

        Http::assertSent(fn (Request $r) => count($r['messages']) === 3 && $r['messages'][2]['content'] === 'E a segunda?');
    }

    public function test_ia_fora_do_ar_mostra_aviso_e_deixa_tentar_de_novo(): void
    {
        $foraDoAr = true;
        Http::fake(function () use (&$foraDoAr) {
            if ($foraDoAr) {
                throw new ConnectionException('fora do ar');
            }

            return Http::response(self::ndjson('Você pode ter até 15 horas de faltas.'));
        });

        $chat = Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Quantas faltas?')->call('enviar')->call('responder')
            ->assertSee('temporariamente indisponível');
        $this->assertSame(1, $this->aluno->conversas()->first()->mensagens()->count()); // só a pergunta

        $foraDoAr = false;
        $chat->call('tentarDeNovo')->call('responder')->assertSee('Você pode ter até 15 horas')->assertDontSee('temporariamente indisponível');
    }

    public function test_aluno_apaga_a_propria_conversa(): void
    {
        $conversa = $this->aluno->conversas()->create(['titulo' => 'Faltas']);

        Livewire::actingAs($this->aluno)->test('conversas-lista')->assertSee('Faltas')->call('apagar', $conversa->id)->assertDontSee('Faltas');

        $this->assertModelMissing($conversa);
    }

    public function test_apagar_a_conversa_aberta_volta_para_uma_nova(): void
    {
        $conversa = $this->aluno->conversas()->create(['titulo' => 'Aberta']);

        Livewire::withQueryParams(['c' => $conversa->id])->actingAs($this->aluno)->test('conversas-lista')
            ->call('apagar', $conversa->id)->assertRedirect(route('aluno.inicio'));
    }

    public function test_aluno_nao_abre_nem_apaga_conversa_de_outro(): void
    {
        $outro = User::factory()->create()->assignRole('aluno');
        $conversa = $outro->conversas()->create(['titulo' => 'Do outro']);

        Livewire::actingAs($this->aluno)->test('conversas-lista')->assertDontSee('Do outro')->call('apagar', $conversa->id)->assertNotFound();
        // pelo endereço (?c=) a conversa de outro também não abre: cai numa conversa nova
        Livewire::withQueryParams(['c' => $conversa->id])->actingAs($this->aluno)->test('pages::aluno.chat')
            ->assertSet('conversaId', null)->assertDontSee('Do outro');

        $this->assertModelExists($conversa);
    }

    public function test_conversas_paradas_ha_mais_de_7_dias_sao_apagadas(): void
    {
        $velha = $this->aluno->conversas()->create(['titulo' => 'Velha']);
        $recente = $this->aluno->conversas()->create(['titulo' => 'Recente']);
        Conversa::whereKey($velha->id)->update(['updated_at' => now()->subDays(8)]);

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat'); // abrir o chat já limpa
        Livewire::actingAs($this->aluno)->test('conversas-lista')->assertSee('Recente')->assertDontSee('Velha');
        $this->assertModelMissing($velha);
        $this->assertModelExists($recente);

        // E pelo agendador, para quem não abre o chat
        Conversa::whereKey($recente->id)->update(['updated_at' => now()->subDays(8)]);
        $this->artisan('model:prune')->assertSuccessful();
        $this->assertModelMissing($recente);
    }

    /** Cria uma conversa com $n perguntas já respondidas. */
    private function conversaCom(int $n): Conversa
    {
        $conversa = $this->aluno->conversas()->create(['titulo' => 'Longa']);
        for ($i = 1; $i <= $n; $i++) {
            $conversa->mensagens()->create(['papel' => 'user', 'conteudo' => "Pergunta $i"]);
            $conversa->mensagens()->create(['papel' => 'assistant', 'conteudo' => "Resposta $i"]);
        }

        return $conversa;
    }

    public function test_chat_sempre_abre_numa_conversa_nova(): void
    {
        $antiga = $this->conversaCom(1);

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->assertSet('conversaId', null)
            ->assertSee('Olá');      // tela de conversa nova
        Livewire::actingAs($this->aluno)->test('conversas-lista')->assertSee('Longa'); // e a antiga continua na barra lateral
        $this->assertModelExists($antiga);
    }

    public function test_aviso_a_partir_de_15_perguntas_conta_quantas_faltam(): void
    {
        $conversa = $this->conversaCom(14);
        Livewire::withQueryParams(['c' => $conversa->id])->actingAs($this->aluno)->test('pages::aluno.chat')
            ->assertDontSee('Esta conversa está ficando longa');

        $conversa->mensagens()->create(['papel' => 'user', 'conteudo' => 'Pergunta 15']);
        Livewire::withQueryParams(['c' => $conversa->id])->actingAs($this->aluno)->test('pages::aluno.chat')
            ->assertSee('Esta conversa está ficando longa')
            ->assertSee('mais 5 pergunta(s)');
    }

    public function test_em_20_perguntas_a_conversa_trava_e_nao_chama_a_ia(): void
    {
        Http::fake();
        $conversa = $this->conversaCom(20);

        Livewire::withQueryParams(['c' => $conversa->id])->actingAs($this->aluno)->test('pages::aluno.chat')
            ->assertSee('chegou ao limite de 20 perguntas')
            ->assertSee('Continuar numa nova conversa')
            ->set('pergunta', 'Mais uma?')->call('enviar')
            ->assertSet('aguardando', false);

        $this->assertSame(20, $conversa->fresh()->perguntas());
        Http::assertNothingSent();
    }

    public function test_pergunta_leva_o_resumo_do_aluno_sem_dados_pessoais_e_mostra_o_cartao(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $ana = User::where('matricula', '202310042')->firstOrFail();
        Http::fake(['*/api/v1/chat/aos-poucos' => Http::response(self::ndjson('Você está de prova final em SAD e precisa de 6,25 (Art. 23).', [
            'tool_called' => 'consultar_situacao_aluno',
            'dados_aluno' => [['disciplina' => 'Sistemas de Apoio à Decisão', 'codigo' => 'SAD', 'semestre' => '2026.2', 'notas' => [5.5, 6.0], 'faltas' => 4, 'situacao' => 'prova_final']],
        ]))]);

        Livewire::actingAs($ana)->test('pages::aluno.chat')
            ->set('pergunta', 'Eu passei em SAD?')->call('enviar')->call('responder')
            ->assertSee('Consultei seus dados')
            ->assertSee('Sistemas de Apoio à Decisão')->assertSee('notas 5,5 · 6,0')->assertSee('4 faltas')->assertSee('Prova final');

        Http::assertSent(function (Request $r) {
            $aluno = $r['aluno'];
            $corpo = $r->body();

            return $aluno['semestre_atual'] === '2026.2'
                && collect($aluno['disciplinas'])->contains(fn ($d) => $d['codigo'] === 'CAL' && $d['situacao_final'] === 'reprovado_nota')
                && ! str_contains($corpo, 'Ana') && ! str_contains($corpo, '202310042') && ! str_contains($corpo, '@aluno')
                && array_keys($aluno) === ['curso', 'periodo_atual', 'semestre_atual', 'ingresso', 'duracao_semestres', 'total_disciplinas', 'carga_horaria_total', 'disciplinas']
                && [$aluno['ingresso'], $aluno['duracao_semestres'], $aluno['total_disciplinas'], $aluno['carga_horaria_total']] === ['2023.1', 8, 40, 2600];
        });
    }

    public function test_cartao_de_consulta_grande_mostra_resumo_e_so_o_que_nao_foi_aprovado(): void
    {
        $dados = collect(range(1, 8))->map(fn ($i) => ['disciplina' => "Disciplina $i", 'codigo' => "D$i", 'semestre' => '2025.1', 'notas' => [8.0], 'faltas' => 0, 'situacao' => 'aprovado_direto'])
            ->push(['disciplina' => 'Cálculo I', 'codigo' => 'CAL', 'semestre' => '2023.2', 'notas' => [3.5, 4.0], 'faltas' => 6, 'situacao' => 'reprovado_nota'])->all();
        Http::fake(['*/api/v1/chat/aos-poucos' => Http::response(self::ndjson('Uma reprovação.', ['tool_called' => 'consultar_situacao_aluno', 'dados_aluno' => $dados]))]);

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Quantas reprovações eu tive?')->call('enviar')->call('responder')
            ->assertSee('9 disciplinas')->assertSee('8 Aprovado')->assertSee('1 Reprovado por nota')
            ->assertSee('Cálculo I')
            ->assertDontSeeHtml('<span class="font-medium">Disciplina 3</span>') // no cartão, só o resumo e o que não foi aprovado
            ->assertSee('Disciplina 3');                                         // a lista completa vai para o painel "Seus dados"
    }

    public function test_secretaria_ou_usuario_sem_ficha_nao_manda_resumo(): void
    {
        $this->iaResponde();
        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')->set('pergunta', 'Oi')->call('enviar')->call('responder');

        Http::assertSent(fn (Request $r) => ! isset($r['aluno']));
    }

    public function test_resposta_vai_para_a_tela_aos_poucos(): void
    {
        $this->iaResponde('Até quinze horas.');

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Faltas?')->call('enviar')->call('responder')
            ->assertSee('Até quinze horas.'); // no fim, a resposta inteira e formatada
        ob_flush();

        // os pedaços foram para a tela enquanto chegavam: o 1º substitui o "Pensando…", os outros somam
        preg_match_all('/\{"stream":true.*?"endStream":true\}/', $this->transmitido, $enviados);
        $pedacos = collect($enviados[0])->map(fn ($e) => json_decode($e, true)['body']);
        $this->assertSame(['Até', ' quinze', ' horas.'], $pedacos->pluck('content')->all());
        $this->assertSame(['replace', 'default', 'default'], $pedacos->pluck('mode')->all());
        $this->assertSame(['resposta'], $pedacos->pluck('name')->unique()->values()->all());
    }

    public function test_erro_no_meio_do_streaming_mostra_o_aviso(): void
    {
        Http::fake(['*/api/v1/chat/aos-poucos' => Http::response(json_encode(['tipo' => 'erro', 'detail' => 'indisponível'])."\n")]);

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Oi')->call('enviar')->call('responder')
            ->assertSee('temporariamente indisponível');
    }

    public function test_conversa_nova_aparece_na_barra_e_fica_no_endereco(): void
    {
        $this->iaResponde();

        $chat = Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Quantas faltas?')->call('enviar')->call('responder');

        $conversa = $this->aluno->conversas()->firstOrFail();
        $chat->assertSet('conversaId', $conversa->id)->assertDispatched('conversas-mudaram', aberta: $conversa->id);
    }

    public function test_resposta_sobre_uma_disciplina_mostra_o_cartao_da_nota_com_a_regua(): void
    {
        Http::fake(['*/api/v1/chat/aos-poucos' => Http::response(self::ndjson('Prova final (Art. 23).', [
            'tool_called' => 'consultar_situacao_aluno',
            'dados_aluno' => [['disciplina' => 'Sistemas de Apoio à Decisão', 'codigo' => 'SAD', 'semestre' => '2026.2', 'notas' => [5.5, 6.0], 'faltas' => 4,
                'situacao' => 'prova_final', 'media_parcial' => 5.75, 'nota_necessaria_final' => 6.25, 'limite_faltas' => 15]],
        ]))]);

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Eu passei em SAD?')->call('enviar')->call('responder')
            ->assertSeeHtml('class="regua"')
            ->assertSee('5,75')->assertSee('6,25')->assertSee('Prova final')
            ->assertSee('1 disciplina consultada')
            ->assertSee('Dados consultados')       // "Seus dados" também abre o painel lateral
            ->assertSee('Ver tudo no Meu perfil');
    }

    public function test_fonte_abre_o_painel_com_o_trecho_e_o_link_do_pdf_na_pagina(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('documentos/r.pdf', '%PDF-1.4 regimento');
        $doc = \App\Models\Documento::create(['nome' => 'Regimento Acadêmico IUVA 2026', 'nome_arquivo' => 'Regimento_Academico_IUVA_2026.pdf',
            'caminho' => 'documentos/r.pdf', 'tamanho' => 18, 'status' => 'publicado']);
        $this->iaResponde();

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'Faltas?')->call('enviar')->call('responder')
            ->assertSee('Trecho citado')
            ->assertSee('Art. 15')
            ->assertSee('pdf#page=2', false); // o botão do PDF já abre na página citada

        $this->actingAs($this->aluno)->get(route('documentos.pdf', $doc))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_aluno_nao_abre_pdf_de_documento_que_nao_esta_publicado(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('documentos/x.pdf', '%PDF');
        $rascunho = \App\Models\Documento::create(['nome' => 'Rascunho', 'nome_arquivo' => 'x.pdf', 'caminho' => 'documentos/x.pdf', 'tamanho' => 4, 'status' => 'a_entrar']);

        $this->actingAs($this->aluno)->get(route('documentos.pdf', $rascunho))->assertNotFound();
        auth()->logout();
        $this->get(route('documentos.pdf', $rascunho))->assertRedirect(route('login'));
    }

    public function test_resposta_que_cai_no_meio_mostra_o_aviso_em_vez_de_quebrar(): void
    {
        // a IA começa a responder e a conexão cai (ex.: passou do tempo)
        $corpo = \GuzzleHttp\Psr7\FnStream::decorate(\GuzzleHttp\Psr7\Utils::streamFor(''), [
            'eof' => fn () => false,
            'read' => fn () => throw new \RuntimeException('Unable to read from stream'),
        ]);
        Http::fake(fn () => \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, [], $corpo)));

        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->set('pergunta', 'qual minha media global')->call('enviar')->call('responder')
            ->assertSee('temporariamente indisponível')
            ->assertSee('Tentar de novo');
    }

    public function test_aviso_de_que_a_ia_pode_errar_fica_fixo_embaixo(): void
    {
        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')
            ->assertSeeText('O Uni Search é um auxiliar e pode errar.');
    }

    public function test_pergunta_vazia_nao_e_enviada(): void
    {
        Http::fake();
        Livewire::actingAs($this->aluno)->test('pages::aluno.chat')->set('pergunta', '')->call('enviar')->assertHasErrors('pergunta');
        Http::assertNothingSent();
    }
}
