<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de asistencia configurables por sesión (Fase 9, ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 2). CalcularAsistenciasSesionJob
 * las lee para decidir presente/asistencia_parcial/ausente a partir de los
 * datos reales recuperados de la API del proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones_en_vivo', function (Blueprint $table) {
            $table->unsignedTinyInteger('porcentaje_minimo_asistencia')->default(80)->after('duracion_minutos');
            $table->unsignedInteger('minutos_minimos_asistencia')->nullable()->after('porcentaje_minimo_asistencia');
            $table->unsignedInteger('tolerancia_minutos')->default(5)->after('minutos_minimos_asistencia');
            // 'porcentaje' | 'minutos' | 'cualquiera' (basta con cumplir uno de los dos criterios)
            $table->string('criterio_cumplimiento', 20)->default('porcentaje')->after('tolerancia_minutos');
            $table->boolean('considerar_tiempo_previo')->default(false)->after('criterio_cumplimiento');
            $table->boolean('considerar_tiempo_posterior')->default(false)->after('considerar_tiempo_previo');
        });
    }

    public function down(): void
    {
        Schema::table('sesiones_en_vivo', function (Blueprint $table) {
            $table->dropColumn([
                'porcentaje_minimo_asistencia', 'minutos_minimos_asistencia', 'tolerancia_minutos',
                'criterio_cumplimiento', 'considerar_tiempo_previo', 'considerar_tiempo_posterior',
            ]);
        });
    }
};
