@php
    $nota = fn ($n) => $n === null ? '—' : number_format($n, 1, ',', '');
    $media = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    $painel = 'rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900';
    $rotulo = 'font-mono text-[0.66rem] uppercase tracking-wider text-zinc-500 dark:text-zinc-400';
@endphp
<x-layouts::app :title="__('Meu perfil')">
    <div class="mx-auto flex max-w-5xl flex-col gap-8">
        {{-- Ficha: só leitura (os dados vêm do sistema acadêmico) --}}
        <section class="{{ $painel }} grid gap-5 p-5 sm:grid-cols-[auto_1fr] sm:items-center">
            <div class="grid size-16 place-items-center rounded-2xl bg-accent/10 font-mono text-xl font-semibold text-accent">{{ $aluno->user->initials() }}</div>
            <div class="grid gap-4">
                <div>
                    <div class="text-xl font-semibold">{{ $aluno->user->name }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $aluno->curso->nome }} · {{ $aluno->periodo_atual }}º período · {{ $aluno->turno }}</div>
                </div>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-5">
                    <div><dt class="{{ $rotulo }}">{{ __('Matrícula') }}</dt><dd class="font-mono">{{ $aluno->user->matricula }}</dd></div>
                    <div><dt class="{{ $rotulo }}">{{ __('Ingresso') }}</dt><dd class="font-mono">{{ $aluno->ingresso }}</dd></div>
                    <div><dt class="{{ $rotulo }}">{{ __('Situação') }}</dt><dd>{{ ucfirst($aluno->situacao_matricula) }}</dd></div>
                    <div><dt class="{{ $rotulo }}">{{ __('Nascimento') }}</dt><dd class="font-mono">{{ $aluno->data_nascimento->format('d/m/Y') }}</dd></div>
                    <div class="col-span-2 md:col-span-1"><dt class="{{ $rotulo }}">{{ __('E-mail') }}</dt><dd class="truncate">{{ $aluno->user->email }}</dd></div>
                </dl>
            </div>
        </section>

        {{-- Semestre em andamento: a situação vem da API (regras do regimento) --}}
        <section class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between">
                <h2 class="text-lg font-semibold">{{ __('Semestre atual') }}</h2>
                <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $emCurso->first()?->semestre }}</span>
            </div>
            @if ($situacoes === null)
                <flux:callout icon="exclamation-triangle" color="amber" heading="Média e situação indisponíveis no momento">
                    O serviço que aplica as regras do regimento está fora do ar. As notas abaixo estão corretas; tente de novo em instantes.
                </flux:callout>
            @endif
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($emCurso as $c)
                    @php $s = $situacoes[$c->disciplina->codigo] ?? null; @endphp
                    <div class="{{ $painel }} grid gap-3 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ $c->disciplina->nome }}</div>
                                <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $c->disciplina->carga_horaria }} h ·
                                    {{ __('notas') }} {{ collect($c->valoresDasNotas())->map($nota)->join(' · ') ?: '—' }}
                                    @if ($c->notas->count() < $c->disciplina->avaliacoes) ({{ $c->notas->count() }} {{ __('de') }} {{ $c->disciplina->avaliacoes }}) @endif
                                </div>
                            </div>
                            @if ($s) <x-situacao :situacao="$s['situacao']" class="shrink-0" /> @endif
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div><div class="{{ $rotulo }}">{{ __('Média') }}</div><div class="text-xl font-bold">{{ $s ? $media($s['media_parcial']) : '—' }}</div></div>
                            <div><div class="{{ $rotulo }}">{{ __('Na final') }}</div><div class="text-xl font-bold text-accent">{{ $s && $s['situacao'] === 'prova_final' ? $media($s['nota_necessaria_final']) : '—' }}</div></div>
                            <div><div class="{{ $rotulo }}">{{ __('Faltas') }}</div><div class="text-xl font-bold">{{ $c->faltas }}@if ($s)<span class="text-sm text-zinc-400">/{{ $s['limite_faltas'] }}</span>@endif</div></div>
                        </div>
                        @if ($s && $s['media_parcial'] !== null)
                            <div class="regua !pt-5">
                                <div class="regua-pino text-zinc-900 dark:text-zinc-100" style="left: {{ max(0, min(100, $s['media_parcial'] * 10)) }}%"></div>
                                <div class="regua-faixas"><span></span><span></span><span></span></div>
                            </div>
                        @endif
                        @if ($s && $s['situacao'] !== 'reprovado_falta' && $s['faltas_restantes'] <= 3)
                            <div class="text-xs text-[var(--color-nota-final)]">{{ __('Só pode faltar mais :h h nesta disciplina.', ['h' => $s['faltas_restantes']]) }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Histórico fechado pela secretaria --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">{{ __('Histórico') }}</h2>
            @forelse ($historico as $semestre => $cursadas)
                <details class="{{ $painel }} group" @if ($loop->first) open @endif>
                    <summary class="flex cursor-pointer items-center justify-between px-4 py-3">
                        <span class="font-mono font-medium">{{ $semestre }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $cursadas->count() }} {{ __('disciplinas') }}
                            @if ($r = $cursadas->filter(fn ($c) => str_starts_with($c->situacao_final, 'reprovado'))->count()) · <span class="text-[var(--color-nota-ruim)]">{{ $r }} {{ __('reprovação(ões)') }}</span> @endif
                        </span>
                    </summary>
                    <ul class="divide-y divide-zinc-200 border-t border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($cursadas->sortBy('disciplina.nome') as $c)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <span class="min-w-0 truncate">{{ $c->disciplina->nome }}</span>
                                <span class="flex shrink-0 items-center gap-4">
                                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ collect($c->valoresDasNotas())->map($nota)->join(' · ') }} · {{ $c->faltas }} {{ __('faltas') }}</span>
                                    <x-situacao :situacao="$c->situacao_final" />
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @empty
                <flux:text>Primeiro semestre: ainda não há histórico.</flux:text>
            @endforelse
        </section>
    </div>
</x-layouts::app>
