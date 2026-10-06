<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacaciones por DÍAS ESPECÍFICOS (FechasSolicitudService): la fuente real
 * de una solicitud de vacaciones es la lista de días elegidos, no un rango.
 * solicitudes_internas.fecha_inicio/fecha_fin/dias_solicitados se siguen
 * llenando (primer día, último día y cuántos) por compatibilidad con
 * reportes, formatos y el cálculo de saldo. Incremental: no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_vacaciones_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_interna_id')->constrained('solicitudes_internas')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->date('fecha');
            $table->timestamps();

            $table->unique(['solicitud_interna_id', 'fecha'], 'sol_vac_dias_solicitud_fecha_unique');
            $table->index(['colaborador_id', 'fecha'], 'sol_vac_dias_colaborador_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_vacaciones_dias');
    }
};
