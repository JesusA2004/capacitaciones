<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vacaciones pendientes y prima vacacional son conceptos distintos
 * (CLAUDE.md §20/§36): el finiquito ya guardaba la prima como un monto
 * aparte, pero el PAGO de los días pendientes (a sueldo diario) nunca tuvo
 * su propia columna — FiniquitoService::desglose() los mezclaba en una sola
 * fila. Backfill seguro: los finiquitos existentes recalculan este valor
 * informativo sin tocar sus totales ya guardados (nunca se altera un
 * finiquito ya firmado/pagado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->decimal('vacaciones_pendientes_pago', 10, 2)->default(0)->after('vacaciones_pendientes');
        });

        DB::table('finiquito_calculos')->update([
            'vacaciones_pendientes_pago' => DB::raw('ROUND(vacaciones_pendientes * sueldo_diario, 2)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->dropColumn('vacaciones_pendientes_pago');
        });
    }
};
