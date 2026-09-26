<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domicilio y teléfono oficiales de cada sucursal (mr-lana.com/sucursales,
 * ver database/data/sucursales_oficiales.php) y la sucursal Corporativo
 * (Subida del Club 114, Cuernavaca), donde vive todo lo general y de
 * servicio a las sucursales.
 *
 * Solo LLENA campos vacíos: nunca pisa un domicilio/teléfono que alguien ya
 * haya capturado. Si ya existe una sucursal llamada "Corporativo…" con otra
 * clave, se reutiliza (no se duplica). No mueve colaboradores de sucursal:
 * eso es un dato real que decide RH.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var array<string, array{nombre?: string, ciudad?: string, estado?: string, direccion: string, telefono: string|null}> $datos */
        $datos = require database_path('data/sucursales_oficiales.php');

        $corporativo = $datos['CORP01'];
        $existe = DB::table('sucursales')
            ->where('clave', 'CORP01')
            ->orWhere('nombre', 'like', 'Corporativo%')
            ->exists();

        if (! $existe) {
            DB::table('sucursales')->insert([
                'empresa_id' => DB::table('empresas')->orderBy('id')->value('id'),
                'nombre' => $corporativo['nombre'] ?? 'Corporativo',
                'clave' => 'CORP01',
                'ciudad' => $corporativo['ciudad'] ?? null,
                'estado' => $corporativo['estado'] ?? null,
                'direccion' => $corporativo['direccion'],
                'telefono' => $corporativo['telefono'],
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($datos as $clave => $sucursal) {
            $consulta = $clave === 'CORP01'
                ? DB::table('sucursales')->where(fn ($q) => $q->where('clave', 'CORP01')->orWhere('nombre', 'like', 'Corporativo%'))
                : DB::table('sucursales')->where('clave', $clave);

            (clone $consulta)->where(fn ($q) => $q->whereNull('direccion')->orWhere('direccion', ''))
                ->update(['direccion' => $sucursal['direccion'], 'updated_at' => now()]);

            if ($sucursal['telefono'] !== null) {
                (clone $consulta)->where(fn ($q) => $q->whereNull('telefono')->orWhere('telefono', ''))
                    ->update(['telefono' => $sucursal['telefono'], 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Datos: no se revierten (un domicilio correcto no se "des-captura").
    }
};
