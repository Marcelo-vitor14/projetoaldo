<?php

use App\Http\Controllers\Aluno\PerfilController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware('auth')->group(function () {
    // Depois do login, cada perfil vai para a sua área
    Route::get('dashboard', fn () => redirect(auth()->user()->paginaInicial()))->name('dashboard');

    // PDF de um documento da base, para abrir na página citada (aluno: só os publicados)
    Route::get('documentos/{documento}/pdf', function (App\Models\Documento $documento) {
        abort_unless(auth()->user()->hasRole('secretaria') || in_array($documento->status, ['publicado', 'a_sair'], true), 404);

        return Illuminate\Support\Facades\Storage::disk('local')->response($documento->caminho, $documento->nome_arquivo, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento->nome_arquivo.'"',
        ]);
    })->name('documentos.pdf');

    Route::middleware('role:aluno')->prefix('aluno')->name('aluno.')->group(function () {
        Route::livewire('/', 'pages::aluno.chat')->name('inicio');
        Route::get('perfil', PerfilController::class)->name('perfil');
    });

    Route::middleware('role:secretaria')->prefix('secretaria')->name('secretaria.')->group(function () {
        Route::livewire('/', 'pages::secretaria.documentos')->name('inicio');
    });
});

require __DIR__.'/settings.php';
