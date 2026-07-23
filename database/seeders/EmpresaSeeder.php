<?php

namespace Database\Seeders;

use App\Models\Empresa;
use Illuminate\Database\Seeder;

class EmpresaSeeder extends Seeder
{
    public function run(): void
    {
        Empresa::firstOrCreate(
            ['nombre' => 'Mr. Lana'],
            ['razon_social' => 'Mr. Lana Servicios Financieros', 'activo' => true],
        );
    }
}
