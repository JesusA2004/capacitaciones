<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de conferencia recuperado del proveedor externo (Google Meet
 * conferenceRecord / Zoom past meeting report) para una sesión en vivo. Es
 * la fuente de verdad de "qué dijo el proveedor que pasó realmente" —
 * separada de `sesiones_en_vivo` (la programación) y de `asistencias` (el
 * resultado ya calculado/corregido para cada colaborador). Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 4 y docs/GOOGLE_MEET.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_sesion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_en_vivo_id')->constrained('sesiones_en_vivo')->cascadeOnDelete();
            $table->string('proveedor', 20);
            // ID de la reunión/evento en el proveedor (event_id de Calendar, meeting_id de Zoom).
            $table->string('identificador_externo')->nullable();
            // ID del registro de conferencia real (conferenceRecord de Meet, UUID de instancia de Zoom).
            $table->string('registro_conferencia_externo')->nullable();
            $table->timestamp('inicio_real')->nullable();
            $table->timestamp('fin_real')->nullable();
            $table->unsignedInteger('duracion_real_segundos')->nullable();
            // pendiente | sincronizado | parcial | error | agotado (máximo de reintentos alcanzado)
            $table->string('estado_sincronizacion', 20)->default('pendiente');
            // Respuesta del proveedor ya normalizada al formato interno (nunca el payload crudo con tokens).
            $table->json('respuesta_normalizada')->nullable();
            $table->unsignedInteger('intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->timestamp('consultado_en')->nullable();
            $table->timestamps();

            $table->unique(['sesion_en_vivo_id', 'proveedor'], 'registros_sesion_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_sesion');
    }
};
