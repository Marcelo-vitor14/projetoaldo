<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Algo mudou nos documentos ou nas versões da base (envio, conferência, publicação, voltar...).
 * Vai pelo Reverb só para a secretaria: o painel aberto se atualiza na hora, inclusive o de outra pessoa.
 */
class DocumentosMudaram implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('secretaria');
    }

    public function broadcastAs(): string
    {
        return 'documentos.mudaram';
    }

    /** Sem dados: o painel só recarrega a lista (nada de conteúdo dos documentos no WebSocket). */
    public function broadcastWith(): array
    {
        return [];
    }

    /** Avisa sem nunca quebrar quem mudou o dado (ex.: Reverb desligado). */
    public static function avisar(): void
    {
        rescue(fn () => broadcast(new static), report: false);
    }
}
