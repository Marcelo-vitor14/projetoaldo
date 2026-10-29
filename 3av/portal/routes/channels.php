<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Painel de documentos: só a secretaria escuta
Broadcast::channel('secretaria', fn ($user) => $user->hasRole('secretaria'));
