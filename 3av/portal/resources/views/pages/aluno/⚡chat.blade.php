<?php

use App\Models\Conversa;
use App\Services\IaApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Assistente acadêmico')] class extends Component {
    /** A conversa aberta fica no endereço (?c=12): dá para abrir pela barra lateral e o F5 não perde. */
    #[Url(as: 'c')]
    public ?int $conversaId = null;

    #[Validate('required|string|max:2000', as: 'pergunta')]
    public string $pergunta = '';

    /** A pergunta já foi salva e a resposta da IA está a caminho. */
    public bool $aguardando = false;

    public ?string $erro = null;

    /** Resposta que acabou de chegar: só ela tem o efeito de digitação (as antigas aparecem inteiras). */
    public ?int $recente = null;

    public function mount(): void
    {
        // Histórico curto: conversas paradas há mais de 7 dias somem ao abrir o chat (e todo dia pelo agendador)
        Auth::user()->conversas()->expiradas()->delete();
        // Sem ?c= no endereço, abre numa conversa nova (as antigas ficam na barra lateral); conversa de outro aluno não abre
        if ($this->conversaId && ! Auth::user()->conversas()->whereKey($this->conversaId)->exists()) {
            $this->conversaId = null;
        }
    }

    #[Computed]
    public function conversa(): ?Conversa
    {
        return $this->conversaId ? Auth::user()->conversas()->with('mensagens')->find($this->conversaId) : null;
    }

    public function exemplo(string $texto): void
    {
        $this->pergunta = $texto;
        $this->enviar();
    }

    /** Salva a pergunta e mostra na hora; a resposta vem numa segunda chamada (responder). */
    public function enviar(): void
    {
        if ($this->aguardando || $this->conversa?->travada()) {
            return; // conversa no limite: só continua numa nova
        }
        $this->validate();

        $conversa = $this->conversa ?? Auth::user()->conversas()->create(['titulo' => Str::limit($this->pergunta, 60)]);
        $conversa->mensagens()->create(['papel' => 'user', 'conteudo' => $this->pergunta]);
        $conversa->touch();

        $this->conversaId = $conversa->id;
        $this->reset('pergunta', 'erro', 'recente');
        $this->aguardando = true;
        unset($this->conversa);
        $this->dispatch('conversas-mudaram', aberta: $conversa->id); // a barra lateral mostra a conversa nova
        $this->js('$wire.responder()');
    }

    public function responder(IaApi $ia): void
    {
        $conversa = $this->conversa;
        if (! $this->aguardando || ! $conversa) {
            return;
        }

        // Resposta aos poucos: cada pedaço vai direto para a tela enquanto o Gemini escreve
        $primeiro = true;
        $resposta = $ia->chatAosPoucos($conversa->historicoParaIa(), 'conversa-'.$conversa->id, Auth::user()->aluno?->resumoParaIa(),
            function (string $pedaco) use (&$primeiro) {
                $this->stream(e($pedaco), $primeiro, 'resposta'); // o 1º substitui o "Pensando…"
                $primeiro = false;
            });
        $this->aguardando = false;

        if ($resposta === null) {
            $this->erro = 'O assistente está temporariamente indisponível. Tente de novo em instantes.';

            return;
        }

        $this->recente = $conversa->mensagens()->create([
            'papel' => 'assistant',
            'conteudo' => $resposta['texto'],
            'fontes' => $resposta['fontes'],
            'calculo' => $resposta['calculo'],
            'dados_aluno' => $resposta['dados_aluno'] ?: null,
        ])->id;
        $conversa->touch();
        unset($this->conversa);
        $this->dispatch('conversas-mudaram', aberta: $conversa->id);
    }

    public function tentarDeNovo(): void
    {
        $this->erro = null;
        $this->aguardando = true;
        $this->js('$wire.responder()');
    }
}; ?>

@php
    // Texto da IA: escapa tudo e só deixa passar **negrito** e quebras de linha
    $formatar = fn (string $t) => nl2br(preg_replace('/\*\*(.+?)\*\*/s', '<strong class="font-semibold">$1</strong>', e($t)));
    $primeiroNome = Str::before(auth()->user()->name, ' ');
    // Documentos da base publicada, para o painel do trecho poder abrir o PDF na página citada
    $docsBase = \App\Models\Documento::whereIn('status', ['publicado', 'a_sair'])->get();
    $fonteParaPainel = function (array $f) use ($docsBase) {
        $doc = $docsBase->first(fn ($d) => in_array($f['documento'], [$d->nome, $d->nome_arquivo], true));

        return [
            'documento' => $doc?->nome ?? Str::of($f['documento'])->beforeLast('.pdf')->replace('_', ' ')->toString(),
            'artigo' => $f['artigo'], 'pagina' => $f['pagina'], 'trecho' => $f['trecho'],
            'pdf' => $doc ? route('documentos.pdf', $doc).'#page='.$f['pagina'] : null,
        ];
    };
    // "Seus dados": todas as disciplinas consultadas, por semestre (o cartão acima pode mostrar só um resumo)
    $dadosParaPainel = fn ($dados) => [
        'tipo' => 'dados',
        'total' => $dados->count(),
        'semestres' => $dados->sortByDesc('semestre')->groupBy('semestre')->map(fn ($itens, $semestre) => [
            'semestre' => $semestre,
            'itens' => $itens->map(fn ($d) => [
                'disciplina' => $d['disciplina'],
                'detalhe' => (collect($d['notas'])->map(fn ($n) => number_format($n, 1, ',', ''))->join(' · ') ?: '—').' · '.$d['faltas'].' faltas',
                'situacao' => \App\Models\Cursada::rotulo($d['situacao']),
                'cor' => match (true) {
                    str_starts_with($d['situacao'], 'aprovado') => 'var(--color-nota-ok)',
                    str_starts_with($d['situacao'], 'reprovado') => 'var(--color-nota-ruim)',
                    $d['situacao'] === 'prova_final' => 'var(--color-nota-final)',
                    default => 'var(--color-zinc-400)',
                },
            ])->values(),
        ])->values(),
    ];
@endphp

{{-- Ocupa toda a área abaixo do cabeçalho (sem a margem padrão das páginas). À direita, o painel do trecho citado. --}}
<div class="-m-6 flex h-[calc(100vh-60px)] lg:-m-8" x-data="{ fonte: null }" x-on:keydown.escape.window="fonte = null">
<div class="flex min-w-0 flex-1 flex-col">
    {{-- relative: nada posicionado aqui dentro "escapa" e estica a página --}}
    <div class="relative flex-1 overflow-y-auto px-6 pb-4 pt-8" id="mensagens">
        <div class="mx-auto flex max-w-3xl flex-col gap-6">
            @if (! $this->conversa)
                {{-- Abertura --}}
                <div class="grid gap-5 pt-[4vh]">
                    <div class="font-mono text-xs uppercase tracking-widest text-accent">{{ __('Assistente acadêmico') }}</div>
                    <h2 class="text-4xl font-bold tracking-tight">{{ __('Olá, :nome!', ['nome' => $primeiroNome]) }} <span class="text-accent">{{ __('Em que posso ajudar?') }}</span></h2>
                    <p class="max-w-xl text-zinc-500 dark:text-zinc-400">{{ __('Pergunte sobre o Regimento e o Manual do Aluno, ou sobre a sua situação: notas, faltas e histórico. As respostas citam o artigo de onde vieram.') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ([
                            ['Seus dados', 'Como estou neste semestre?'],
                            ['Seus dados', 'Quantas faltas ainda posso ter em cada disciplina?'],
                            ['Art. 15', 'Quantas faltas posso ter numa disciplina de 60 horas?'],
                            ['Calculadora', 'Tirei 6, 5,5 e 6,5 nas provas. Quanto preciso na final?'],
                        ] as [$ref, $ex])
                            <button type="button" wire:click="exemplo(@js($ex))"
                                class="grid gap-1.5 rounded-2xl border border-zinc-200 bg-white p-4 text-start transition hover:-translate-y-0.5 hover:border-accent dark:border-zinc-700 dark:bg-zinc-900">
                                <span class="font-mono text-[0.7rem] text-accent">{{ $ref }}</span>
                                <span class="font-medium">{{ $ex }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                @foreach ($this->conversa->mensagens as $m)
                    @if ($m->doAluno())
                        <div wire:key="m-{{ $m->id }}" class="flex justify-end">
                            <div class="max-w-[75%] rounded-2xl rounded-br-md border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">{!! nl2br(e($m->conteudo)) !!}</div>
                        </div>
                    @else
                        @php
                            $dados = collect($m->dados_aluno ?? []);
                            $emCurso = $dados->filter(fn ($d) => isset($d['limite_faltas']));
                        @endphp
                        <div wire:key="m-{{ $m->id }}" class="grid gap-3"
                            @if ($m->id === $recente) x-data="digitar()" x-init="revelar($refs.texto)" x-bind:class="{ 'esperando-digitar': !pronto }" @endif>
                            <div class="font-mono text-[0.7rem] tracking-wide text-accent">› uni search</div>
                            <div class="leading-relaxed" x-ref="texto">{!! $formatar($m->conteudo) !!}</div>

                            {{-- Cartão da nota: pela calculadora, ou quando a resposta é sobre uma disciplina em curso --}}
                            @if ($m->calculo)
                                <x-cartao-nota :titulo="__('Calculadora')" :notas="$m->calculo['notas'] ?? []" :media="$m->calculo['media_parcial']"
                                    :precisa="$m->calculo['nota_necessaria_final']" :situacao="['aprovado_direto' => 'aprovado_direto', 'reprovado_direto' => 'reprovado_nota'][$m->calculo['status']] ?? 'prova_final'" />
                            @elseif ($emCurso->count() === 1 && $dados->count() === 1)
                                @php $d = $emCurso->first(); @endphp
                                <x-cartao-nota :titulo="$d['disciplina'].' · '.$d['semestre']" :notas="$d['notas']" :media="$d['media_parcial'] ?? null"
                                    :precisa="$d['nota_necessaria_final'] ?? null" :faltas="$d['faltas']" :limite="$d['limite_faltas']" :situacao="$d['situacao']" />
                            @elseif ($dados->isNotEmpty())
                                @php
                                    // Várias disciplinas (ex.: o semestre ou o histórico): lista; se for grande, resumo e só o que não foi aprovação
                                    $resumido = $dados->count() > 5;
                                    $lista = $resumido ? $dados->reject(fn ($d) => str_starts_with($d['situacao'], 'aprovado'))->take(5) : $dados;
                                @endphp
                                <div class="max-w-xl rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                                    <div class="font-mono text-[0.68rem] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Consultei seus dados') }}</div>
                                    @if ($resumido)
                                        <div class="mt-1.5 text-sm">{{ $dados->count() }} {{ __('disciplinas') }}
                                            @foreach ($dados->countBy('situacao') as $situacao => $n) · {{ $n }} {{ \App\Models\Cursada::rotulo($situacao) }} @endforeach
                                        </div>
                                    @endif
                                    <ul class="mt-2 divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                                        @foreach ($lista as $d)
                                            <li class="flex items-center justify-between gap-3 py-2">
                                                <span class="min-w-0"><span class="font-medium">{{ $d['disciplina'] }}</span>
                                                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $d['semestre'] }} · {{ __('notas') }} {{ collect($d['notas'])->map(fn ($n) => number_format($n, 1, ',', ''))->join(' · ') ?: '—' }} · {{ $d['faltas'] }} {{ __('faltas') }}</span></span>
                                                <x-situacao :situacao="$d['situacao']" class="shrink-0" />
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- Fontes linkadas embaixo da mensagem (clique abre o trecho no painel da direita) --}}
                            @if ($m->fontes || $dados->isNotEmpty())
                                <div class="flex flex-wrap items-start gap-2">
                                    @foreach ($m->fontes ?? [] as $i => $f)
                                        @php $painel = $fonteParaPainel($f); $chave = $m->id.'-'.$i; @endphp
                                        <button type="button" x-on:click="fonte = fonte?.chave === '{{ $chave }}' ? null : { chave: '{{ $chave }}', ...@js($painel) }"
                                            x-bind:class="fonte?.chave === '{{ $chave }}' && '!border-accent'"
                                            class="inline-flex gap-2 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 font-mono text-[0.76rem] hover:border-accent dark:border-zinc-700 dark:bg-zinc-900">
                                            <span class="text-accent">{{ $f['artigo'] }}</span>{{ Str::of($painel['documento'])->before(' ') }} · p. {{ $f['pagina'] }}
                                        </button>
                                    @endforeach
                                    @if ($dados->isNotEmpty())
                                        @php $chave = $m->id.'-dados'; @endphp
                                        <button type="button" x-on:click="fonte = fonte?.chave === '{{ $chave }}' ? null : { chave: '{{ $chave }}', ...@js($dadosParaPainel($dados)) }"
                                            x-bind:class="fonte?.chave === '{{ $chave }}' && '!border-accent'"
                                            class="inline-flex gap-2 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 font-mono text-[0.76rem] hover:border-accent dark:border-zinc-700 dark:bg-zinc-900">
                                            <span class="text-accent">{{ __('Seus dados') }}</span>{{ trans_choice(':n disciplina consultada|:n disciplinas consultadas', $dados->count(), ['n' => $dados->count()]) }}
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            @endif

            @if ($aguardando)
                {{-- O texto chega no span escondido (wire:stream) e aparece letra por letra no visível (resources/js/app.js) --}}
                <div wire:key="digitando" class="grid gap-3" x-data="digitar()" x-init="espelhar($refs.fonte, $refs.saida)">
                    <div class="font-mono text-[0.7rem] tracking-wide text-accent">› uni search</div>
                    <span x-ref="fonte" wire:stream="resposta" class="hidden"></span>
                    <div class="whitespace-pre-wrap leading-relaxed"><span x-ref="saida"></span><span x-show="!comecou" class="relative inline-flex gap-1.5 py-2"><i class="size-2 animate-pulse rounded-full bg-accent"></i><i class="size-2 animate-pulse rounded-full bg-accent [animation-delay:.15s]"></i><i class="size-2 animate-pulse rounded-full bg-accent [animation-delay:.3s]"></i><span class="sr-only">{{ __('Pensando…') }}</span></span></div>
                </div>
            @endif

            @if ($this->conversa && ! $aguardando)
                @php
                    $feitas = $this->conversa->perguntas();
                    $limite = config('uni-search.limite_perguntas');
                @endphp
                @if ($this->conversa->travada())
                    <flux:callout icon="lock-closed" color="zinc" :heading="__('Esta conversa chegou ao limite de :n perguntas.', ['n' => $limite])">
                        <flux:callout.text>{{ __('O assistente é para dúvidas rápidas. Continue numa conversa nova: esta fica na lista para consulta.') }}</flux:callout.text>
                    </flux:callout>
                @elseif ($feitas >= config('uni-search.aviso_perguntas'))
                    <flux:callout icon="information-circle" color="amber" :heading="__('Esta conversa está ficando longa.')">
                        <flux:callout.text>
                            {{ __('O assistente só lembra das últimas mensagens. Você pode fazer mais :resta pergunta(s) aqui; depois a conversa será bloqueada. Para uma dúvida nova, é melhor começar uma nova conversa.', ['resta' => $limite - $feitas]) }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
            @endif

            @if ($erro)
                <flux:callout icon="exclamation-triangle" color="amber" :heading="$erro">
                    <x-slot name="actions"><flux:button size="sm" wire:click="tentarDeNovo">{{ __('Tentar de novo') }}</flux:button></x-slot>
                </flux:callout>
            @endif
        </div>
    </div>

    <div class="px-6 pb-6 pt-3">
        <div class="mx-auto max-w-3xl">
            @if ($this->conversa?->travada())
                <div class="flex justify-center"><flux:button variant="primary" icon="plus" :href="route('aluno.inicio')" wire:navigate>{{ __('Continuar numa nova conversa') }}</flux:button></div>
            @else
                <form wire:submit="enviar" class="flex gap-2 rounded-2xl border border-zinc-200 bg-white p-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <input wire:model="pergunta" placeholder="{{ __('Escreva sua dúvida…') }}" maxlength="2000" autocomplete="off"
                        class="min-w-0 flex-1 bg-transparent px-3 py-2 outline-none placeholder:text-zinc-400" />
                    <flux:button type="submit" variant="primary" :disabled="$aguardando">{{ __('Enviar') }}</flux:button>
                </form>
                @error('pergunta') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            @endif
            {{-- Aviso fixo (em vez de repetir em toda resposta): a IA é um auxiliar e pode errar --}}
            <p class="mt-2 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ __('O Uni Search é um auxiliar e pode errar. Confira no Regimento ou com a Secretaria Acadêmica.') }}</p>
        </div>
    </div>

</div>

    {{-- Trecho citado: abre à direita ao clicar numa fonte (Esc ou × fecha) --}}
    <aside x-show="fonte" x-cloak x-transition.opacity class="flex w-[400px] shrink-0 flex-col gap-4 overflow-y-auto border-s border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-900/60">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold" x-text="fonte?.tipo === 'dados' ? '{{ __('Dados consultados') }}' : '{{ __('Trecho citado') }}'">{{ __('Trecho citado') }}</h2>
            <button type="button" x-on:click="fonte = null" aria-label="{{ __('Fechar') }}"
                class="grid size-8 place-items-center rounded-lg border border-zinc-200 text-zinc-500 hover:text-zinc-900 dark:border-zinc-700 dark:text-zinc-400 dark:hover:text-white">×</button>
        </div>
        {{-- "Seus dados": o que a IA consultou do aluno para responder --}}
        <template x-if="fonte?.tipo === 'dados'">
            <div class="grid gap-4">
                <p class="text-sm text-zinc-500 dark:text-zinc-400" x-text="'{{ __('Para responder, o assistente consultou') }} ' + fonte.total + (fonte.total === 1 ? ' {{ __('disciplina') }}' : ' {{ __('disciplinas') }}') + ' {{ __('do seu cadastro (sem nome nem matrícula).') }}'"></p>
                <template x-for="grupo in fonte.semestres" x-bind:key="grupo.semestre">
                    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-200 px-3 py-2 font-mono text-xs font-medium dark:border-zinc-700" x-text="grupo.semestre"></div>
                        <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                            <template x-for="item in grupo.itens" x-bind:key="item.disciplina">
                                <li class="grid gap-0.5 px-3 py-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="min-w-0 truncate" x-text="item.disciplina"></span>
                                        <span class="shrink-0 text-xs font-semibold" x-bind:style="'color:' + item.cor" x-text="item.situacao"></span>
                                    </div>
                                    <span class="font-mono text-[0.7rem] text-zinc-500 dark:text-zinc-400" x-text="item.detalhe"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
                <a href="{{ route('aluno.perfil') }}" wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm hover:border-accent dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon name="academic-cap" variant="micro" /> {{ __('Ver tudo no Meu perfil') }}
                </a>
            </div>
        </template>

        {{-- Fonte de um documento: o trecho grifado, como na 2ª AV --}}
        <template x-if="fonte && fonte.tipo !== 'dados'">
            <div class="grid gap-3">
                <article class="folha-doc">
                    <div class="cab"><span x-text="fonte.documento"></span><span>IUVA · 2026</span></div>
                    <p><mark x-text="fonte.trecho"></mark></p>
                    <div class="pe" x-text="fonte.artigo + ' · página ' + fonte.pagina"></div>
                </article>
                <template x-if="fonte.pdf">
                    <a x-bind:href="fonte.pdf" target="_blank" rel="noopener"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm hover:border-accent dark:border-zinc-700 dark:bg-zinc-900">
                        <flux:icon name="document-text" variant="micro" /> <span x-text="'{{ __('Abrir o PDF na página') }} ' + fonte.pagina"></span>
                    </a>
                </template>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('O assistente responde só com base nestes trechos dos documentos oficiais da instituição.') }}</p>
            </div>
        </template>
    </aside>

    {{-- Rola para a última mensagem sempre que a conversa muda --}}
    @script
    <script>
        const rolar = () => { const el = document.getElementById('mensagens'); if (el) el.scrollTop = el.scrollHeight; };
        rolar();
        $wire.$hook('morphed', rolar);
    </script>
    @endscript
</div>
