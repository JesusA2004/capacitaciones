<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura hija de `sesiones_participante`: una fila por cada
 * entrada/salida real reportada por el proveedor (participantSessions de
 * Google Meet, join/leave de Zoom). Varias filas para el mismo participante
 * = varias reconexiones; la suma de `duracion_segundos` es la evidencia de
 * los minutos totales calculados. Ver docs/AUDITORIA_CUMPLIMIENTO.md
 * sección 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entradas_salidas_participante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_participante_id')->constrained('sesiones_participante')->cascadeOnDelete();
            $table->timestamp('inicio');
            $table->timestamp('fin')->nullable();
            $table->unsignedInteger('duracion_segundos')->nullable();
            // google_meet | zoom | manual (para correcciones administrativas)
            $table->string('origen', 20);
            $table->string('identificador_externo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entradas_salidas_participante');
    }
};
