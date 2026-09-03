<?php

namespace Database\Seeders;

use App\Models\Departamento;
use Illuminate\Database\Seeder;

class DepartamentoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Recursos Humanos', 'Operaciones', 'Ventas', 'Sistemas', 'Contabilidad'] as $nombre) {
            Departamento::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
