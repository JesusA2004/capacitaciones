<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_en_vivo_id')->constrained('sesiones_en_vivo')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Conecta la asistencia con el detalle real de participación
            // recuperado del proveedor, cuando existe (ver registros_sesion).
            $table->foreignId('sesion_participante_id')->nullable()->constrained('sesiones_participante')->nullOnDelete();
            $table->string('estado')->default('pendiente');
            $table->timestamp('unido_en')->nullable();
            $table->timestamp('salido_en')->nullable();
            $table->unsignedInteger('duracion_segundos')->nullable();
            $table->unsignedInteger('minutos_totales')->nullable();
            $table->unsignedTinyInteger('porcentaje_sesion')->nullable();
            $table->unsignedInteger('numero_reconexiones')->default(0);
            $table->text('motivo_estado')->nullable();
            $table->timestamp('sincronizado_en')->nullable();
            $table->foreignId('corregido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_correccion')->nullable();
            // Auditoria de la corrección manual: estado/minutos previos,
            // evidencia, IP, user-agent y origen.
            $table->string('estado_anterior', 30)->nullable();
            $table->unsignedInteger('minutos_anteriores')->nullable();
            $table->string('evidencia_correccion')->nullable();
            $table->string('correccion_ip', 45)->nullable();
            $table->string('correccion_user_agent', 255)->nullable();
            $table->string('correccion_origen', 30)->nullable();
            $table->timestamps();

            $table->unique(['sesion_en_vivo_id', 'user_id'], 'asistencias_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
