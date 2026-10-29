<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Conversas do aluno na barra lateral: abrir (pelo endereço ?c=), começar uma nova e apagar. */
new class extends Component {
    /** A conversa aberta no chat (vem do endereço e é avisada pelo chat quando muda). */
    public ?int $aberta = null;

    public function mount(): void
    {
        $this->aberta = request()->integer('c') ?: null;
    }

    #[Computed]
    public function conversas()
    {
        return Auth::user()->conversas()->latest('updated_at')->get(['id', 'titulo', 'updated_at']);
    }

    #[On('conversas-mudaram')]
    public function atualizar(?int $aberta = null): void
    {
        if ($aberta) {
            $this->aberta = $aberta;
        }
        unset($this->conversas);
    }

    public function apagar(int $id): void
    {
        Auth::user()->conversas()->findOrFail($id)->delete(); // só as do próprio aluno
        if ($this->aberta === $id) {
            $this->redirect(route('aluno.inicio'), navigate: true);

            return;
        }
        unset($this->conversas);
    }
}; ?>

<div class="flex min-h-0 flex-1 flex-col">
    <flux:button variant="primary" icon="plus" :href="route('aluno.inicio')" wire:navigate class="w-full">{{ __('Nova conversa') }}</flux:button>

    <div class="mx-2.5 mb-1.5 mt-5 font-mono text-[0.66rem] uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Conversas') }}</div>
    <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto">
        @forelse ($this->conversas as $c)
            <div wire:key="conversa-{{ $c->id }}" @class(['group flex items-center rounded-lg text-sm',
                'bg-zinc-100 text-zinc-900 shadow-[inset_2px_0_0_var(--color-accent)] dark:bg-zinc-800 dark:text-zinc-100' => $c->id === $aberta,
                'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100' => $c->id !== $aberta])>
                <a href="{{ route('aluno.inicio', ['c' => $c->id]) }}" wire:navigate class="min-w-0 flex-1 truncate px-2.5 py-2">{{ $c->titulo }}</a>
                <flux:button size="xs" variant="subtle" icon="trash" wire:click="apagar({{ $c->id }})" wire:confirm="{{ __('Apagar esta conversa?') }}"
                    :aria-label="__('Apagar conversa')" class="opacity-0 group-hover:opacity-70" />
            </div>
        @empty
            <div class="px-2.5 py-2 text-xs text-zinc-500">{{ __('Nenhuma conversa ainda.') }}</div>
        @endforelse
        <div class="mx-2.5 mt-1.5 font-mono text-[0.66rem] text-zinc-400 dark:text-zinc-600">{{ __('guardadas por :d dias', ['d' => config('uni-search.historico_dias')]) }}</div>
    </nav>
</div>
