@php
    $usuario = auth()->user();
    $ficha = $usuario?->aluno; // só alunos têm
    $quem = $ficha
        ? $ficha->curso->nome.' · '.$ficha->periodo_atual.'º período'
        : ($usuario?->email ?? '');
@endphp
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="fundo-uni flex min-h-screen flex-col !p-0">
        <header class="flex h-[60px] shrink-0 items-center justify-between gap-4 border-b border-zinc-200 px-6 lg:px-8 dark:border-zinc-700">
            <h1 class="truncate text-base font-medium">{{ $title ?? '' }}</h1>
            @if ($usuario)
                <div class="text-end leading-tight">
                    <div class="text-sm font-semibold">{{ $usuario->name }}</div>
                    <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $quem }}</div>
                </div>
            @endif
        </header>
        <div class="min-h-0 flex-1 p-6 lg:p-8">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
