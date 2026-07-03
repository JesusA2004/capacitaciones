<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un participante detectado por el proveedor dentro de un `registro_sesion`
 * (Fase 9). `user_id` es nullable a propósito: un participante externo o
 * anónimo no tiene colaborador asociado. La asociación automática con
 * `user_id` solo ocurre por coincidencia confiable de correo electrónico
 * (nunca por nombre) — ver App\Services\Reuniones\AsociadorParticipanteService
 * y docs/AUDITORIA_CUMPLIMIENTO.md sección 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_participante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_sesion_id')->constrained('registros_sesion')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('identificador_externo')->nullable();
            $table->string('correo_detectado')->nullable();
            $table->string('nombre_mostrado')->nullable();
            // interno | externo | anonimo
            $table->string('tipo_participante', 20)->default('anonimo');
            // identificado | pendiente_revision | anonimo
            $table->string('estado_identificacion', 20)->default('pendiente_revision');
            $table->unsignedInteger('minutos_acumulados')->default(0);
            $table->unsignedTinyInteger('porcentaje_sesion')->default(0);
            $table->unsignedInteger('numero_reconexiones')->default(0);
            // Resultado calculado por CalcularAsistenciasSesionJob antes de
            // aplicarlo a `asistencias` (presente | asistencia_parcial | ausente | pendiente_revision).
            $table->string('resultado_calculado', 30)->nullable();
            $table->timestamps();

            $table->index(['registro_sesion_id', 'correo_detectado'], 'sesiones_participante_correo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_participante');
    }
};
