<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cada intento de sincronización de asistencia de una sesión
 * en vivo (manual desde el panel, automática por Job, o disparada por
 * webhook). Es lo que el panel administrativo muestra como "estado de
 * sincronización" y lo que permite reintentar/diagnosticar sin adivinar
 * qué pasó. Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 4-5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sincronizaciones_reunion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_en_vivo_id')->constrained('sesiones_en_vivo')->cascadeOnDelete();
            $table->string('proveedor', 20);
            // manual | automatica | webhook | reintento
            $table->string('tipo_sincronizacion', 20);
            // en_progreso | completada | parcial | error | agotada
            $table->string('estado', 20)->default('en_progreso');
            $table->timestamp('iniciado_en');
            $table->timestamp('finalizado_en')->nullable();
            $table->unsignedInteger('intentos')->default(1);
            $table->unsignedInteger('cantidad_participantes')->nullable();
            $table->text('error')->nullable();
            $table->json('resumen')->nullable();
            $table->string('job_id')->nullable();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sesion_en_vivo_id', 'estado'], 'sincronizaciones_reunion_sesion_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sincronizaciones_reunion');
    }
};
