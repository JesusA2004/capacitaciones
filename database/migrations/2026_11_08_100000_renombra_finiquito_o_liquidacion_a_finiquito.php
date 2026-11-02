<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En PEOPLE no existen liquidaciones: toda baja se cierra con FINIQUITO. El
 * documento requerido de la no renovación sembrado como «Finiquito o
 * liquidación firmado» pasa a «Finiquito firmado». Solo se renombra si RH no
 * lo editó (el seeder nunca pisa lo que RH cambió). No borra datos: las
 * columnas de indemnización de las causales se conservan como legado.
 */
return new class extends Migration
{
    private const CLAVE = 'NR.finiquito';

    private const ANTERIOR = 'Finiquito o liquidación firmado';

    private const NUEVO = 'Finiquito firmado';

    public function up(): void
    {
        if (! Schema::hasTable('documentos_requeridos_baja')) {
            return;
        }

        DB::table('documentos_requeridos_baja')
            ->where('clave', self::CLAVE)
            ->where('nombre', self::ANTERIOR)
            ->update(['nombre' => self::NUEVO]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('documentos_requeridos_baja')) {
            return;
        }

        DB::table('documentos_requeridos_baja')
            ->where('clave', self::CLAVE)
            ->where('nombre', self::NUEVO)
            ->update(['nombre' => self::ANTERIOR]);
    }
};
