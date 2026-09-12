<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_en_vivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leccion_id')->unique()->constrained('lecciones')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('proveedor')->default('manual');
            $table->dateTime('fecha_inicio');
            $table->unsignedInteger('duracion_minutos')->default(60);
            // Reglas de asistencia configurables por sesión: CalcularAsistenciasSesionJob
            // las lee para decidir presente/asistencia_parcial/ausente a partir de
            // los datos reales recuperados de la API del proveedor.
            $table->unsignedTinyInteger('porcentaje_minimo_asistencia')->default(80);
            $table->unsignedInteger('minutos_minimos_asistencia')->nullable();
            $table->unsignedInteger('tolerancia_minutos')->default(5);
            // 'porcentaje' | 'minutos' | 'cualquiera' (basta con cumplir uno de los dos criterios)
            $table->string('criterio_cumplimiento', 20)->default('porcentaje');
            $table->boolean('considerar_tiempo_previo')->default(false);
            $table->boolean('considerar_tiempo_posterior')->default(false);
            $table->string('enlace_reunion')->nullable();
            $table->string('id_reunion_externa')->nullable();
            $table->json('datos_proveedor')->nullable();
            $table->string('estado')->default('programada');
            // Marca de cuando se envio el ultimo recordatorio automatico, para
            // que los comandos programados no vuelvan a notificar por la
            // misma sesion en cada ejecucion del scheduler.
            $table->timestamp('recordatorio_enviado_en')->nullable();
            $table->foreignId('creado_por')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['estado', 'fecha_inicio'], 'sesiones_en_vivo_estado_fecha_inicio_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_en_vivo');
    }
};
