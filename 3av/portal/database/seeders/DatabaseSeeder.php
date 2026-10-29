<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Perfis, a secretaria, o curso e os alunos de teste (tudo fictício).
     */
    public function run(): void
    {
        Role::findOrCreate('aluno');
        Role::findOrCreate('secretaria');

        User::factory()->create([
            'name' => 'Secretaria Acadêmica',
            'email' => 'secretaria@iuva.edu.br',
        ])->assignRole('secretaria');

        $this->call([CursoSeeder::class, AlunosSeeder::class, DocumentosSeeder::class]);
    }
}
