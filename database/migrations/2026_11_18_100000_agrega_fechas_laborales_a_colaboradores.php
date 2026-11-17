<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fechas laborales del colaborador (docs/FECHAS_LABORALES.md). Aditiva: todas
 * las columnas nacen vacías y NO se rellenan con datos existentes.
 *
 *  - fecha_induccion: cuándo tomó su inducción (la captura RH; sirve sobre
 *    todo a la plantilla importada, que la tomó antes de PEOPLE).
 *  - periodo_prueba_cantidad + periodo_prueba_unidad: periodo de prueba
 *    ESPECÍFICO de la persona (p. ej. 2 meses o 45 días). Vacío = hereda
 *    puestos.meses_periodo_prueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            if (! Schema::hasColumn('colaboradores', 'fecha_induccion')) {
                $table->date('fecha_induccion')->nullable()->after('periodo_prueba_fin');
            }

            if (! Schema::hasColumn('colaboradores', 'periodo_prueba_cantidad')) {
                $table->unsignedSmallInteger('periodo_prueba_cantidad')->nullable()->after('fecha_induccion');
            }

            if (! Schema::hasColumn('colaboradores', 'periodo_prueba_unidad')) {
                $table->string('periodo_prueba_unidad', 10)->nullable()->after('periodo_prueba_cantidad');
            }
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropColumn(['fecha_induccion', 'periodo_prueba_cantidad', 'periodo_prueba_unidad']);
        });
    }
};
