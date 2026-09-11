<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cantidad real de plazas detrás de una vacante (docs/HEADCOUNT_Y_VACANTES.md):
 * antes una fila de `vacantes` representaba "hay un hueco" sin decir cuántas
 * personas hacen falta. `plazas_requeridas`/`plazas_disponibles` reflejan en
 * vivo el faltante de App\Services\Headcount\HeadcountService::vacantesDerivadas()
 * (se actualizan en cada App\Services\Vacantes\VacanteAutoGenerationService::sincronizar());
 * `plazas_cubiertas` cuenta candidatos con estado "contratado" ligados a esta
 * vacante — informativo, no se resta de las otras dos (el faltante ya
 * descuenta a los colaboradores activos reales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacantes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('plazas_requeridas')->default(0)->after('headcount_target_id');
            $table->unsignedSmallInteger('plazas_cubiertas')->default(0)->after('plazas_requeridas');
            $table->unsignedSmallInteger('plazas_disponibles')->default(0)->after('plazas_cubiertas');
        });
    }

    public function down(): void
    {
        Schema::table('vacantes', function (Blueprint $table): void {
            $table->dropColumn(['plazas_requeridas', 'plazas_cubiertas', 'plazas_disponibles']);
        });
    }
};
