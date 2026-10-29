@props([
    'sidebar' => false,
    'grande' => false,
])

{{-- Marca do Uni Search: "Uni" + "Search" em azul, e a assinatura do IUVA embaixo --}}
<a {{ $attributes->merge(['class' => 'flex flex-col gap-1 '.($grande ? 'items-center text-center' : 'px-2')]) }}>
    <span @class(['font-bold leading-none tracking-tight text-zinc-900 dark:text-zinc-50', 'text-3xl' => $grande, 'text-xl' => ! $grande])>
        Uni <span class="text-accent">Search</span>
    </span>
    <span @class(['font-mono leading-snug tracking-wide text-zinc-500 dark:text-zinc-400', 'text-xs' => $grande, 'text-[0.62rem]' => ! $grande])>
        <span class="text-accent">IUVA</span> · Instituto Universitário Vale do Aldo
    </span>
</a>
