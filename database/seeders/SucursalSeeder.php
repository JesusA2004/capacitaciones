<?php

namespace Database\Seeders;

use App\Models\Sucursal;
use Illuminate\Database\Seeder;

class SucursalSeeder extends Seeder
{
    public function run(): void
    {
        $sucursales = [
            ['nombre' => 'Corporativo Monterrey', 'clave' => 'MTY01', 'ciudad' => 'Monterrey', 'estado' => 'Nuevo León'],
            ['nombre' => 'Sucursal Ciudad de México', 'clave' => 'CDMX01', 'ciudad' => 'Ciudad de México', 'estado' => 'CDMX'],
            ['nombre' => 'Sucursal Guadalajara', 'clave' => 'GDL01', 'ciudad' => 'Guadalajara', 'estado' => 'Jalisco'],
        ];

        foreach ($sucursales as $sucursal) {
            Sucursal::firstOrCreate(
                ['clave' => $sucursal['clave']],
                [...$sucursal, 'activo' => true],
            );
        }
    }
}
