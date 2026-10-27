<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desglose oficial de la dirección de cada sucursal (estado, ciudad,
 * municipio, colonia y C.P.) tomado de database/data/sucursales_oficiales.php
 * (mr-lana.com/sucursales).
 *
 * Los documentos jurídicos —contrato de confidencialidad, etc.— imprimen el
 * ESTADO y el MUNICIPIO de la sucursal del colaborador leyendo estas columnas;
 * antes solo se poblaba `direccion` (texto libre) y `estado`/`ciudad` únicamente
 * en instalaciones nuevas, por lo que esos espacios salían en blanco. Aquí se
 * rellenan para las bases ya migradas.
 *
 * Solo LLENA columnas vacías: nunca pisa un dato que alguien ya haya capturado
 * a mano. No se inventa nada: cada valor viene del mismo domicilio oficial.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var array<string, array<string, string|null>> $datos */
        $datos = require database_path('data/sucursales_oficiales.php');

        foreach ($datos as $clave => $sucursal) {
            $consulta = $clave === 'CORP01'
                ? DB::table('sucursales')->where(fn ($q) => $q->where('clave', 'CORP01')->orWhere('nombre', 'like', 'Corporativo%'))
                : DB::table('sucursales')->where('clave', $clave);

            foreach (['estado', 'ciudad', 'municipio', 'colonia', 'codigo_postal'] as $columna) {
                $valor = $sucursal[$columna] ?? null;

                if ($valor === null || $valor === '') {
                    continue;
                }

                (clone $consulta)
                    ->where(fn ($q) => $q->whereNull($columna)->orWhere($columna, ''))
                    ->update([$columna => $valor, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Datos: no se revierten (un domicilio correcto no se "des-captura").
    }
};
