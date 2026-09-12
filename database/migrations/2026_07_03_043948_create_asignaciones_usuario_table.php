<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asignaciones ya materializadas por usuario. Se conservan aunque el
     * usuario cambie despues de sucursal, puesto o rol, preservando el
     * historial de a que se le asigno y cuando.
     */
    public function up(): void
    {
        Schema::create('asignaciones_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_id')->constrained('asignaciones')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('estado')->default('pendiente');
            $table->dateTime('fecha_limite')->nullable();
            $table->timestamp('completado_en')->nullable();
            // Marca de cuando se envio el ultimo recordatorio automatico, para
            // que los comandos programados no vuelvan a notificar por la
            // misma fecha limite en cada ejecucion del scheduler.
            $table->timestamp('recordatorio_enviado_en')->nullable();
            $table->timestamps();

            $table->unique(['asignacion_id', 'user_id'], 'asignaciones_usuario_unico');
            $table->index(['estado', 'fecha_limite'], 'asignaciones_usuario_estado_fecha_limite_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones_usuario');
    }
};
