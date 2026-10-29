@props(['situacao' => null])

{{-- Etiqueta da situação de uma disciplina (cores da régua: vermelho, âmbar, verde) --}}
@php
    $cor = match (true) {
        str_starts_with((string) $situacao, 'aprovado') => 'border-[color-mix(in_srgb,var(--color-nota-ok)_40%,transparent)] text-[var(--color-nota-ok)]',
        str_starts_with((string) $situacao, 'reprovado') => 'border-[color-mix(in_srgb,var(--color-nota-ruim)_40%,transparent)] text-[var(--color-nota-ruim)]',
        $situacao === 'prova_final' => 'border-[color-mix(in_srgb,var(--color-nota-final)_40%,transparent)] text-[var(--color-nota-final)]',
        default => 'border-zinc-300 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold $cor"]) }}>{{ \App\Models\Cursada::rotulo($situacao) }}</span>
