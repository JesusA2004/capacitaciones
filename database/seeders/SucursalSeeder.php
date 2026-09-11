<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Seeder;

class SucursalSeeder extends Seeder
{
    public function run(): void
    {
        $empresaId = Empresa::query()->value('id');

        $sucursales = [
            // Sucursales reales (headcount, ver claude/headcount/ y
            // docs/HEADCOUNT_Y_VACANTES.md): mismos nombres exactos que el
            // Excel para que `headcount:importar` encuentre coincidencia sin
            // adivinar, y para que los datos demo reflejen la operación real
            // en vez de 3 sucursales genéricas.
            ['nombre' => 'Ixtlahuaca', 'clave' => 'IXT01', 'ciudad' => 'Ixtlahuaca', 'estado' => 'Estado de México'],
            ['nombre' => 'Cuernavaca', 'clave' => 'CUE01', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos'],
            ['nombre' => 'Atlacomulco', 'clave' => 'ATC01', 'ciudad' => 'Atlacomulco', 'estado' => 'Estado de México'],
            ['nombre' => 'Miacatlan', 'clave' => 'MIA01', 'ciudad' => 'Miacatlán', 'estado' => 'Morelos'],
            ['nombre' => 'San Luis Potosí', 'clave' => 'SLP01', 'ciudad' => 'San Luis Potosí', 'estado' => 'San Luis Potosí'],
            ['nombre' => 'San Juan del Río', 'clave' => 'SJR01', 'ciudad' => 'San Juan del Río', 'estado' => 'Querétaro'],
            ['nombre' => 'Tenango del Valle', 'clave' => 'TEN01', 'ciudad' => 'Tenango del Valle', 'estado' => 'Estado de México'],
            ['nombre' => 'Atlixco', 'clave' => 'ATL01', 'ciudad' => 'Atlixco', 'estado' => 'Puebla'],
            ['nombre' => 'Córdoba', 'clave' => 'COR01', 'ciudad' => 'Córdoba', 'estado' => 'Veracruz'],
            ['nombre' => 'Huamantla', 'clave' => 'HUA01', 'ciudad' => 'Huamantla', 'estado' => 'Tlaxcala'],
            ['nombre' => 'Orizaba', 'clave' => 'ORI01', 'ciudad' => 'Orizaba', 'estado' => 'Veracruz'],
            ['nombre' => 'Tula de Allende', 'clave' => 'TUL01', 'ciudad' => 'Tula de Allende', 'estado' => 'Hidalgo'],
            ['nombre' => 'Tlaxcala', 'clave' => 'TLX01', 'ciudad' => 'Tlaxcala', 'estado' => 'Tlaxcala'],
        ];

        foreach ($sucursales as $sucursal) {
            Sucursal::firstOrCreate(
                ['clave' => $sucursal['clave']],
                [...$sucursal, 'empresa_id' => $empresaId, 'activo' => true],
            );
        }
    }
}
