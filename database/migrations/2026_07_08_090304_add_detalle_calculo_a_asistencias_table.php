<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia del cálculo de asistencia (Fase 9, ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 2): el sistema debe poder explicar
 * quién fue identificado, cuándo entró/salió, cuántas reconexiones tuvo,
 * cuántos minutos permaneció, qué porcentaje cubrió y por qué quedó en el
 * estado final. `unido_en`/`salido_en`/`duracion_segundos` ya existían
 * (Fase 5) pero nunca se llenaban; esta migración agrega el resto y una
 * columna hija (`sesion_participante_id`) que conecta la asistencia con el
 * detalle real de participación cuando existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencias', function (Blueprint $table) {
            $table->foreignId('sesion_participante_id')->nullable()->after('user_id')
                ->constrained('sesiones_participante')->nullOnDelete();
            $table->unsignedInteger('minutos_totales')->nullable()->after('duracion_segundos');
            $table->unsignedTinyInteger('porcentaje_sesion')->nullable()->after('minutos_totales');
            $table->unsignedInteger('numero_reconexiones')->default(0)->after('porcentaje_sesion');
            $table->text('motivo_estado')->nullable()->after('numero_reconexiones');
            $table->timestamp('sincronizado_en')->nullable()->after('motivo_estado');
            // Auditoria de la corrección manual (Fase 9, seccion 6 del encargo):
            // estado/minutos previos, evidencia, IP, user-agent y origen.
            $table->string('estado_anterior', 30)->nullable()->after('motivo_correccion');
            $table->unsignedInteger('minutos_anteriores')->nullable()->after('estado_anterior');
            $table->string('evidencia_correccion')->nullable()->after('minutos_anteriores');
            $table->string('correccion_ip', 45)->nullable()->after('evidencia_correccion');
            $table->string('correccion_user_agent', 255)->nullable()->after('correccion_ip');
            $table->string('correccion_origen', 30)->nullable()->after('correccion_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('asistencias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sesion_participante_id');
            $table->dropColumn([
                'minutos_totales', 'porcentaje_sesion', 'numero_reconexiones', 'motivo_estado', 'sincronizado_en',
                'estado_anterior', 'minutos_anteriores', 'evidencia_correccion', 'correccion_ip',
                'correccion_user_agent', 'correccion_origen',
            ]);
        });
    }
};
