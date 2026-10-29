@props([
    'titulo',
    'notas' => [],
    'media' => null,
    'precisa' => null,
    'faltas' => null,
    'limite' => null,
    'situacao' => null,
])

{{-- Cartão da nota (vindo da 2ª AV): média, quanto precisa na final, faltas e a régua 0-4-7-10 --}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',');
    $pino = $media === null ? null : max(0, min(100, $media * 10));
@endphp
<div {{ $attributes->merge(['class' => 'grid max-w-xl gap-3.5 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900']) }}>
    <div class="flex justify-between gap-3 font-mono text-[0.68rem] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
        <span class="truncate">{{ $titulo }}</span>
        @if ($notas) <span class="shrink-0">{{ __('notas') }} {{ collect($notas)->map(fn ($n) => number_format($n, 1, ',', ''))->join(' · ') }}</span> @endif
    </div>
    <div class="grid grid-cols-3 gap-3">
        <div><div class="font-mono text-[0.66rem] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Média parcial') }}</div><div class="text-2xl font-bold">{{ $media === null ? '—' : $fmt($media) }}</div></div>
        <div><div class="font-mono text-[0.66rem] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Precisa na final') }}</div><div class="text-2xl font-bold text-accent">{{ $precisa === null ? '—' : $fmt($precisa) }}</div></div>
        @if ($faltas !== null)
            <div><div class="font-mono text-[0.66rem] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Faltas') }}</div><div class="text-2xl font-bold">{{ $faltas }}@if ($limite !== null)<span class="text-base text-zinc-400">/{{ $limite }}</span>@endif</div></div>
        @endif
    </div>
    @if ($pino !== null)
        <div class="regua">
            <div class="regua-pino text-zinc-900 dark:text-zinc-100" style="left: {{ $pino }}%">{{ $fmt($media) }}</div>
            <div class="regua-faixas"><span></span><span></span><span></span></div>
            <div class="regua-marcos"><span style="left:0">0</span><span style="left:40%">4</span><span style="left:70%">7</span><span style="left:100%">10</span></div>
        </div>
    @endif
    @if ($situacao) <x-situacao :situacao="$situacao" class="justify-self-start" /> @endif
</div>
