<?php

namespace App\Http\Controllers\Aluno;

use App\Http\Controllers\Controller;
use App\Services\IaApi;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PerfilController extends Controller
{
    public function __invoke(Request $request, IaApi $ia): View
    {
        $aluno = $request->user()->aluno()->with(['curso', 'cursadas.disciplina', 'cursadas.notas'])->firstOrFail();
        $emCurso = $aluno->emCurso();

        return view('aluno.perfil', [
            'aluno' => $aluno,
            'emCurso' => $emCurso,
            'situacoes' => $ia->situacoes($emCurso), // null = API fora do ar
            'historico' => $aluno->historico(),
        ]);
    }
}
