<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{-- Barra lateral única: marca; no meio as conversas (aluno) ou os documentos (secretaria); embaixo perfil e configurações --}}
        <flux:sidebar sticky collapsible="mobile" class="!w-[272px] border-e border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header class="!mb-3">
                <x-app-logo :href="route('dashboard')" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @role('aluno')
                <livewire:conversas-lista />
            @else
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="document-text" :href="route('secretaria.inicio')" :current="request()->routeIs('secretaria.*')" wire:navigate>
                        {{ __('Documentos') }}
                    </flux:sidebar.item>
                </flux:sidebar.nav>
                <flux:spacer />
            @endrole

            <div class="mt-3 flex flex-col gap-0.5 border-t border-zinc-200 pt-2 dark:border-zinc-700">
                @role('aluno')
                    <flux:sidebar.item icon="academic-cap" :href="route('aluno.perfil')" :current="request()->routeIs('aluno.perfil')" wire:navigate>{{ __('Meu perfil') }}</flux:sidebar.item>
                @endrole
                {{-- Configurações abre um menu ali mesmo: conta, segurança, tema (troca na hora) e sair --}}
                <flux:dropdown position="top" align="start" class="w-full">
                    <button type="button" @class(['flex h-10 w-full cursor-pointer items-center gap-3 rounded-lg px-3 text-start text-sm font-medium',
                        'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white' => request()->is('settings/*'),
                        'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white' => ! request()->is('settings/*')])>
                        <flux:icon name="cog-6-tooth" variant="outline" class="size-4 shrink-0" />
                        <span class="flex-1">{{ __('Configurações') }}</span>
                        <flux:icon name="chevron-up-down" variant="micro" class="shrink-0 opacity-60" />
                    </button>

                    <flux:menu class="!w-[248px]">
                        <div class="px-2 py-1.5">
                            <div class="truncate text-sm font-semibold">{{ auth()->user()->name }}</div>
                            <div class="truncate font-mono text-[0.7rem] text-zinc-500 dark:text-zinc-400">{{ auth()->user()->matricula ?? auth()->user()->email }}</div>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item icon="user-circle" :href="route('conta.edit')" wire:navigate>{{ __('Minha conta') }}</flux:menu.item>
                        <flux:menu.item icon="shield-check" :href="route('security.edit')" wire:navigate>{{ __('Senha e segurança') }}</flux:menu.item>
                        <flux:menu.separator />
                        <div class="px-2 pb-1 pt-1.5 font-mono text-[0.66rem] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Tema') }}</div>
                        <div class="grid grid-cols-3 gap-1 px-1 pb-1" x-data>
                            @foreach (['light' => ['sun', 'Claro'], 'dark' => ['moon', 'Escuro'], 'system' => ['computer-desktop', 'Sistema']] as $valor => [$icone, $nome])
                                <button type="button" x-on:click="$flux.appearance = '{{ $valor }}'"
                                    x-bind:class="$flux.appearance === '{{ $valor }}' ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                    class="flex flex-col items-center gap-1 rounded-md py-1.5 text-[0.7rem]">
                                    <flux:icon :name="$icone" variant="micro" />{{ __($nome) }}
                                </button>
                            @endforeach
                        </div>
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" variant="danger" class="w-full cursor-pointer" data-test="logout-button">{{ __('Sair da conta') }}</flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </flux:sidebar>

        {{-- Celular: botão que abre a barra --}}
        <flux:header class="border-b border-zinc-200 bg-white lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <x-app-logo :href="route('dashboard')" wire:navigate class="!gap-0 [&>span:last-child]:hidden" />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
