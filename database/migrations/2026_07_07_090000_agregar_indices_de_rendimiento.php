<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8: indices para las columnas que ya se consultan con frecuencia por
 * estado/fecha (dashboards, reportes, calendario y los comandos de
 * recordatorio de la Fase 7), pero que no tenian indice propio: las
 * columnas de llave foranea ya quedan indexadas automaticamente por
 * foreignId()->constrained(), pero "estado" y las columnas de fecha no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asignaciones_usuario', function (Blueprint $table) {
            $table->index(['estado', 'fecha_limite'], 'asignaciones_usuario_estado_fecha_limite_idx');
        });

        Schema::table('sesiones_en_vivo', function (Blueprint $table) {
            $table->index(['estado', 'fecha_inicio'], 'sesiones_en_vivo_estado_fecha_inicio_idx');
        });

        Schema::table('intentos_cuestionario', function (Blueprint $table) {
            $table->index('estado');
        });

        Schema::table('entregas_actividad', function (Blueprint $table) {
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::table('asignaciones_usuario', function (Blueprint $table) {
            $table->dropIndex('asignaciones_usuario_estado_fecha_limite_idx');
        });

        Schema::table('sesiones_en_vivo', function (Blueprint $table) {
            $table->dropIndex('sesiones_en_vivo_estado_fecha_inicio_idx');
        });

        Schema::table('intentos_cuestionario', function (Blueprint $table) {
            $table->dropIndex(['estado']);
        });

        Schema::table('entregas_actividad', function (Blueprint $table) {
            $table->dropIndex(['estado']);
        });
    }
};
