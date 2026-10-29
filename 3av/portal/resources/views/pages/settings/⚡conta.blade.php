<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configurações · conta')] class extends Component {
    //
}; ?>

@php
    $usuario = auth()->user();
    $rotulo = 'font-mono text-[0.66rem] uppercase tracking-wider text-zinc-500 dark:text-zinc-400';
@endphp
<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Conta')" :subheading="__('Seus dados de acesso. Para mudar nome ou e-mail, fale com a Secretaria Acadêmica.')">
        <div class="grid gap-6">
            <div class="flex items-center gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid size-12 shrink-0 place-items-center rounded-xl bg-accent/10 font-mono font-semibold text-accent">{{ $usuario->initials() }}</div>
                <div class="min-w-0">
                    <div class="truncate font-semibold">{{ $usuario->name }}</div>
                    <div class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $usuario->hasRole('secretaria') ? __('Secretaria Acadêmica') : __('Aluno') }}</div>
                </div>
            </div>

            <dl class="grid gap-4 sm:grid-cols-2">
                @if ($usuario->matricula)
                    <div><dt class="{{ $rotulo }}">{{ __('Entra com a matrícula') }}</dt><dd class="font-mono">{{ $usuario->matricula }}</dd></div>
                @endif
                <div><dt class="{{ $rotulo }}">{{ __('E-mail') }}</dt><dd class="truncate">{{ $usuario->email }}</dd></div>
            </dl>

            @if ($usuario->aluno)
                <flux:text class="text-sm">{{ __('Seu curso, notas e histórico ficam em') }} <flux:link :href="route('aluno.perfil')" wire:navigate>{{ __('Meu perfil') }}</flux:link>.</flux:text>
            @endif

            <flux:separator variant="subtle" />

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="danger" icon="arrow-right-start-on-rectangle" data-test="logout-button">{{ __('Sair da conta') }}</flux:button>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
