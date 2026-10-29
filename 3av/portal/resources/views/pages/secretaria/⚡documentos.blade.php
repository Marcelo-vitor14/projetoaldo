<?php

use App\Jobs\ConferirDocumento;
use App\Jobs\PublicarBase;
use App\Models\Documento;
use App\Models\Publicacao;
use App\Services\IaApi;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Documentos do assistente')] class extends Component {
    use WithFileUploads;

    public $arquivo = null;

    public string $nome = '';

    /** Documento publicado que o novo vai substituir na próxima atualização (opcional). */
    public ?int $substituiId = null;

    protected function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'mimes:pdf', 'max:'.config('uni-search.documento_max_kb')],
            'nome' => ['nullable', 'string', 'max:120'],
            'substituiId' => ['nullable', 'integer', 'exists:documentos,id'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['arquivo' => 'arquivo', 'nome' => 'nome', 'substituiId' => 'documento substituído'];
    }

    #[Computed]
    public function documentos()
    {
        return Documento::visiveis()->with(['remetente', 'substitui'])->orderByRaw("case status when 'publicado' then 1 when 'a_sair' then 1 else 0 end")->latest('id')->get();
    }

    #[Computed]
    public function ativa(): ?Publicacao
    {
        return Publicacao::ativa();
    }

    #[Computed]
    public function historico()
    {
        return Publicacao::with('autor')->latest('versao')->take(8)->get();
    }

    #[Computed]
    public function montando(): bool
    {
        return Publicacao::emMontagem();
    }

    /** O que muda na próxima atualização: quem entra e quem sai. */
    #[Computed]
    public function pendencias(): array
    {
        return [
            'entram' => $this->documentos->where('status', 'a_entrar')->pluck('nome')->values()->all(),
            'saem' => $this->documentos->where('status', 'a_sair')->pluck('nome')->values()->all(),
        ];
    }

    /** Motivo para o botão de atualizar estar travado (null = pode atualizar). */
    #[Computed]
    public function bloqueio(): ?string
    {
        return match (true) {
            $this->montando => __('Já existe uma atualização em andamento.'),
            $this->documentos->contains('status', 'conferindo') => __('Espere a conferência dos PDFs terminar.'),
            $this->documentos->contains('status', 'invalido') => __('Há documento com erro: cancele o envio dele antes de atualizar.'),
            ! $this->pendencias['entram'] && ! $this->pendencias['saem'] => __('Nenhuma alteração pendente.'),
            ! Documento::daProximaVersao()->exists() => __('A base não pode ficar sem nenhum documento.'),
            default => null,
        };
    }

    /** Tempo real: o Reverb avisa quando algo muda (conferência, publicação, ou outra pessoa da secretaria mexendo). */
    #[On('echo-private:secretaria,.documentos.mudaram')]
    public function mudou(): void
    {
        $this->limpar();
    }

    /** Algo sendo conferido ou montado (a consulta a cada 15 s fica só de reserva, se o Reverb cair). */
    #[Computed]
    public function emAndamento(): bool
    {
        return $this->montando || $this->documentos->contains('status', 'conferindo');
    }

    /** Ao escolher o arquivo, sugere o nome a partir dele (Regimento_2027.pdf → "Regimento 2027"). */
    public function updatedArquivo(): void
    {
        $this->validateOnly('arquivo');
        if ($this->nome === '' && $this->arquivo) {
            $this->nome = Str::of($this->arquivo->getClientOriginalName())->beforeLast('.')->replace(['_', '-'], ' ')->squish()->toString();
        }
    }

    /** Enviar só entra no rascunho: a IA confere o PDF, mas a base só muda no botão de atualizar. */
    public function enviar(): void
    {
        $this->validate();

        $original = $this->arquivo->getClientOriginalName();
        $documento = DB::transaction(function () use ($original) {
            $documento = Documento::create([
                'nome' => $this->nome !== '' ? $this->nome : Str::beforeLast($original, '.'),
                'nome_arquivo' => $original,
                'caminho' => $this->arquivo->storeAs('documentos', Str::uuid().'.pdf', 'local'),
                'substitui_id' => $this->substituiId,
                'tamanho' => $this->arquivo->getSize(),
                'status' => 'conferindo',
                'enviado_por' => Auth::id(),
            ]);
            $documento->update(['chave_ia' => 'portal-'.$documento->id]);
            if ($this->substituiId) {
                Documento::whereKey($this->substituiId)->where('status', 'publicado')->update(['status' => 'a_sair']);
            }

            return $documento;
        });
        ConferirDocumento::dispatch($documento);

        $this->reset('arquivo', 'nome', 'substituiId');
        $this->limpar();
        Flux::toast(variant: 'success', text: __('Documento enviado. Ele entra no assistente quando você atualizar a base.'));
    }

    /** Publicado → vai sair na próxima atualização. */
    public function tirar(int $id): void
    {
        Documento::whereKey($id)->where('status', 'publicado')->update(['status' => 'a_sair']);
        $this->limpar();
    }

    /** Vai sair → continua (desfaz). */
    public function manter(int $id): void
    {
        $documento = Documento::whereKey($id)->where('status', 'a_sair')->firstOrFail();
        $documento->update(['status' => 'publicado']);
        // se ele ia sair por causa de uma substituição, o substituto vira só um documento novo
        Documento::where('substitui_id', $id)->update(['substitui_id' => null]);
        $this->limpar();
    }

    /** Cancela um envio que ainda não entrou na base (apaga o PDF e devolve o substituído). */
    public function cancelar(int $id): void
    {
        $documento = Documento::whereKey($id)->whereIn('status', ['conferindo', 'a_entrar', 'invalido'])->firstOrFail();
        if ($documento->substitui_id) {
            Documento::whereKey($documento->substitui_id)->where('status', 'a_sair')->update(['status' => 'publicado']);
        }
        $documento->delete();
        $this->limpar();
    }

    public function atualizarBase(): void
    {
        unset($this->bloqueio);
        if ($motivo = $this->bloqueio) {
            Flux::toast(variant: 'danger', text: $motivo);

            return;
        }

        $publicacao = Publicacao::create([
            'versao' => (int) Publicacao::max('versao') + 1,
            'status' => 'montando',
            'mudancas' => $this->pendencias,
            'publicado_por' => Auth::id(),
        ]);
        PublicarBase::dispatch($publicacao);

        Flux::modal('confirmar-atualizacao')->close();
        $this->limpar();
        Flux::toast(text: __('Montando a versão :v. O assistente continua usando a atual até ela ficar pronta.', ['v' => $publicacao->versao]));
    }

    /** Desfaz a última publicação: a versão anterior volta na hora e as mudanças voltam a ficar pendentes. */
    public function voltar(IaApi $ia): void
    {
        $ativa = Publicacao::ativa();
        $anterior = Publicacao::anterior();
        if (! $ativa || ! $anterior || $this->montando) {
            return;
        }

        $resultado = $ia->voltarBase();
        if (! $resultado['ok']) {
            Flux::toast(variant: 'danger', text: __('Não foi possível voltar: :erro', ['erro' => $resultado['erro']]));

            return;
        }

        DB::transaction(function () use ($ativa, $anterior) {
            $ativa->update(['status' => 'descartada']);
            $anterior->update(['status' => 'ativa']);
            $naAnterior = $anterior->idsDosDocumentos();
            // quem saiu na versão desfeita volta; quem entrou nela volta a ficar pendente
            Documento::whereIn('id', $naAnterior)->where('status', 'removido')->update(['status' => 'publicado']);
            Documento::whereNotIn('id', $naAnterior)->where('status', 'publicado')->update(['status' => 'a_entrar']);
        });

        $this->limpar();
        Flux::toast(variant: 'success', text: __('O assistente voltou para a versão :v.', ['v' => $anterior->versao]));
    }

    private function limpar(): void
    {
        unset($this->documentos, $this->ativa, $this->historico, $this->montando, $this->pendencias, $this->bloqueio, $this->emAndamento);
    }
}; ?>

<div class="flex max-w-5xl flex-col gap-8" @if ($this->emAndamento) wire:poll.15s @endif>
    <div>
        <flux:text>{{ __('O assistente responde só com base na versão publicada destes documentos. Enviar ou remover não muda nada na hora: as mudanças ficam pendentes até você atualizar a base.') }}</flux:text>
    </div>

    {{-- Base publicada + atualização --}}
    <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Base do assistente') }}</flux:heading>
                @if ($this->ativa)
                    <flux:text>
                        {{ __('Versão :v', ['v' => $this->ativa->versao]) }} · {{ __('publicada em :data', ['data' => $this->ativa->updated_at->format('d/m/Y H:i')]) }}
                        @if ($this->ativa->autor) {{ __('por :nome', ['nome' => $this->ativa->autor->name]) }} @endif
                        · {{ count($this->ativa->documentos ?? []) }} {{ __('documentos') }} · {{ $this->ativa->trechos }} {{ __('trechos') }}
                    </flux:text>
                @else
                    <flux:text>{{ __('Nenhuma versão publicada ainda.') }}</flux:text>
                @endif
            </div>
            <flux:modal.trigger name="confirmar-atualizacao">
                <flux:button variant="primary" icon="arrow-path" :disabled="(bool) $this->bloqueio">{{ __('Atualizar a base do assistente') }}</flux:button>
            </flux:modal.trigger>
        </div>

        @if ($this->montando)
            <flux:callout icon="arrow-path" color="blue" :heading="__('Montando a nova versão…')">
                <flux:callout.text>{{ __('A IA está processando todos os documentos do zero. Enquanto isso, o assistente continua usando a versão atual.') }}</flux:callout.text>
            </flux:callout>
        @elseif (($ultima = $this->historico->first()) && $ultima->status === 'falhou')
            <flux:callout icon="exclamation-triangle" color="red" :heading="__('A versão :v não foi publicada.', ['v' => $ultima->versao])">
                <flux:callout.text>{{ $ultima->erro }} {{ __('As alterações continuam pendentes; você pode tentar de novo.') }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->pendencias['entram'] || $this->pendencias['saem'])
            <div class="text-sm">
                <div class="font-medium">{{ __('Alterações pendentes') }}</div>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($this->pendencias['entram'] as $n) <li class="text-blue-600">+ {{ $n }}</li> @endforeach
                    @foreach ($this->pendencias['saem'] as $n) <li class="text-amber-600">− {{ $n }}</li> @endforeach
                </ul>
            </div>
        @endif
        @if ($this->bloqueio && ! $this->montando)
            <flux:text class="text-xs">{{ $this->bloqueio }}</flux:text>
        @endif
    </section>

    <flux:modal name="confirmar-atualizacao" class="max-w-md">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Atualizar a base do assistente?') }}</flux:heading>
            <flux:text>{{ __('A IA vai montar uma versão nova com todos os documentos abaixo. Só quando ela estiver completa o assistente passa a usá-la.') }}</flux:text>
            <ul class="text-sm">
                @foreach ($this->pendencias['entram'] as $n) <li class="text-blue-600">+ {{ $n }}</li> @endforeach
                @foreach ($this->pendencias['saem'] as $n) <li class="text-amber-600">− {{ $n }}</li> @endforeach
            </ul>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="atualizarBase">{{ __('Atualizar') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Envio --}}
    <form wire:submit="enviar" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Enviar documento') }}</flux:heading>
        <div class="grid gap-4 md:grid-cols-2">
            <flux:input type="file" wire:model="arquivo" accept="application/pdf" :label="__('Arquivo PDF (até :mb MB)', ['mb' => intdiv(config('uni-search.documento_max_kb'), 1024)])" />
            <flux:input wire:model="nome" :label="__('Nome que aparece na lista e nas citações')" :placeholder="__('Ex.: Regimento Acadêmico 2027')" />
        </div>
        <flux:select wire:model="substituiId" :label="__('Substitui um documento? (opcional)')" :description="__('O antigo sai na mesma atualização em que o novo entra.')">
            <flux:select.option value="">{{ __('Não, é um documento novo') }}</flux:select.option>
            @foreach ($this->documentos->where('status', 'publicado') as $d)
                <flux:select.option :value="$d->id">{{ $d->nome }}</flux:select.option>
            @endforeach
        </flux:select>
        <div wire:loading wire:target="arquivo" class="text-sm text-zinc-500">{{ __('Carregando o arquivo…') }}</div>
        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="arquivo,enviar">{{ __('Enviar') }}</flux:button>
        </div>
    </form>

    {{-- Lista --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-2">{{ __('Documento') }}</th><th class="px-3 py-2">{{ __('Situação') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Trechos') }}</th><th class="px-3 py-2">{{ __('Enviado') }}</th><th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->documentos as $d)
                    <tr wire:key="doc-{{ $d->id }}">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ $d->nome }}</div>
                            <div class="text-xs text-zinc-500">{{ $d->nome_arquivo }} · {{ $d->tamanhoLegivel() }}</div>
                        </td>
                        <td class="px-3 py-2">
                            <flux:badge size="sm" :color="\App\Models\Documento::CORES[$d->status] ?? 'zinc'">{{ $d->rotulo() }}</flux:badge>
                            @if ($d->erro) <div class="mt-1 text-xs text-red-600">{{ $d->erro }}</div> @endif
                            @if ($d->substitui) <div class="mt-1 text-xs text-zinc-500">{{ __('Substitui: :nome', ['nome' => $d->substitui->nome]) }}</div> @endif
                        </td>
                        <td class="px-3 py-2 text-right">{{ $d->trechos ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-zinc-500">{{ $d->created_at->format('d/m/Y H:i') }}<br>{{ $d->remetente?->name }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            @if ($d->status === 'publicado')
                                <flux:button size="sm" variant="subtle" icon="minus-circle" wire:click="tirar({{ $d->id }})">{{ __('Tirar') }}</flux:button>
                            @elseif ($d->status === 'a_sair')
                                <flux:button size="sm" variant="subtle" icon="arrow-uturn-left" wire:click="manter({{ $d->id }})">{{ __('Manter') }}</flux:button>
                            @else
                                <flux:button size="sm" variant="subtle" icon="x-mark" wire:click="cancelar({{ $d->id }})"
                                    wire:confirm="{{ __('Cancelar este envio? O PDF é apagado.') }}">{{ __('Cancelar envio') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-zinc-500">{{ __('Nenhum documento.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Histórico de versões --}}
    <section class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Histórico de versões') }}</flux:heading>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->historico as $p)
                        <tr wire:key="pub-{{ $p->id }}">
                            <td class="px-4 py-2 font-medium">{{ __('Versão :v', ['v' => $p->versao]) }}</td>
                            <td class="px-3 py-2"><flux:badge size="sm" :color="\App\Models\Publicacao::CORES[$p->status] ?? 'zinc'">{{ \App\Models\Publicacao::ROTULOS[$p->status] ?? $p->status }}</flux:badge></td>
                            <td class="px-3 py-2 text-xs">
                                @foreach ($p->mudancas['entram'] ?? [] as $n) <span class="text-blue-600">+ {{ $n }}</span> @endforeach
                                @foreach ($p->mudancas['saem'] ?? [] as $n) <span class="text-amber-600">− {{ $n }}</span> @endforeach
                            </td>
                            <td class="px-3 py-2 text-xs text-zinc-500">{{ $p->created_at->format('d/m/Y H:i') }} · {{ $p->autor?->name }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($p->status === 'anterior' && ! $this->montando)
                                    <flux:button size="sm" variant="subtle" icon="arrow-uturn-left" wire:click="voltar"
                                        wire:confirm="{{ __('Voltar o assistente para a versão :v? A versão atual é descartada e as mudanças dela voltam a ficar pendentes.', ['v' => $p->versao]) }}">{{ __('Voltar para esta') }}</flux:button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
