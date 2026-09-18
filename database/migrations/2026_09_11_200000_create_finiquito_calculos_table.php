<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cálculo de finiquito ligado a una solicitud de baja de colaborador (ver
 * App\Services\Finiquitos\FiniquitoService, docs/SOLICITUDES_UNIFICADAS.md).
 * Una fila por solicitud (se actualiza al recalcular, nunca se duplica);
 * `snapshot` congela los datos usados la última vez que se generó el PDF,
 * para que un ajuste posterior no reescriba silenciosamente un documento ya
 * entregado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finiquito_calculos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_interna_id')->unique()->constrained('solicitudes_internas')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('calculado_por_id')->constrained('users');
            $table->foreignId('revisado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('fecha_calculo');
            $table->date('fecha_ingreso');
            $table->date('fecha_baja');

            $table->decimal('sueldo_mensual', 10, 2);
            $table->decimal('sueldo_diario', 10, 2);
            $table->unsignedSmallInteger('antiguedad_anios');
            $table->unsignedSmallInteger('antiguedad_meses');
            $table->unsignedSmallInteger('dias_trabajados_periodo');
            $table->unsignedSmallInteger('vacaciones_pendientes');

            $table->decimal('prima_vacacional', 10, 2)->default(0);
            $table->decimal('aguinaldo_proporcional', 10, 2)->default(0);
            $table->decimal('sueldo_pendiente', 10, 2)->default(0);
            $table->decimal('indemnizacion', 10, 2)->default(0);
            $table->decimal('bonos_extra', 10, 2)->default(0);
            $table->decimal('descuentos', 10, 2)->default(0);
            $table->decimal('adeudos', 10, 2)->default(0);
            $table->json('otros_conceptos')->nullable();

            $table->decimal('total_calculado', 10, 2)->default(0);
            $table->decimal('total_ajustado', 10, 2)->default(0);
            $table->text('comentarios_ajuste')->nullable();
            $table->json('snapshot')->nullable();

            $table->string('estado')->default('borrador');
            $table->string('documento_generado_path')->nullable();
            $table->string('documento_firmado_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finiquito_calculos');
    }
};
