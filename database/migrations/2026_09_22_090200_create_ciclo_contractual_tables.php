<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo contractual: relación contractual (contratos_laborales), evaluación
 * de periodo de prueba y cierre laboral. Ver docs/backend-rh-completion.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos_laborales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estado', 20)->default('vigente');
            // Snapshot de las condiciones al firmar: el contrato histórico no
            // cambia si después cambian sueldo/puesto del colaborador.
            $table->decimal('sueldo_mensual', 10, 2)->nullable();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->foreignId('contrato_anterior_id')->nullable()->constrained('contratos_laborales')->nullOnDelete();
            $table->timestamp('aviso_vencimiento_en')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['estado', 'fecha_fin'], 'contratos_laborales_estado_fin_idx');
            $table->index(['colaborador_id', 'estado'], 'contratos_laborales_colaborador_estado_idx');
        });

        Schema::create('evaluaciones_periodo_prueba', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            // unique: el scheduler puede correr varias veces, nunca crea dos
            // evaluaciones para el mismo contrato.
            $table->foreignId('contrato_laboral_id')->unique()->constrained('contratos_laborales')->cascadeOnDelete();
            $table->foreignId('evaluador_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente');
            $table->date('fecha_limite')->nullable();
            $table->date('fecha_evaluacion')->nullable();
            $table->json('criterios')->nullable();
            $table->decimal('calificacion', 5, 2)->nullable();
            $table->string('resultado', 20)->nullable();
            $table->boolean('recomienda_renovar')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('capturada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('capturada_en')->nullable();
            $table->foreignId('autorizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('autorizada_en')->nullable();
            $table->boolean('decision_renovar')->nullable();
            $table->text('comentario_autorizacion')->nullable();
            $table->foreignId('contrato_renovacion_id')->nullable()->constrained('contratos_laborales')->nullOnDelete();
            $table->timestamps();

            $table->index('estado');
        });

        Schema::create('cierres_laborales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('solicitud_interna_id')->nullable()->unique()->constrained('solicitudes_internas')->nullOnDelete();
            $table->foreignId('evaluacion_id')->nullable()->constrained('evaluaciones_periodo_prueba')->nullOnDelete();
            $table->string('tipo_baja', 30);
            $table->text('motivo');
            $table->date('fecha_efectiva');
            $table->string('estado', 30)->default('iniciado');
            $table->timestamp('aviso_registrado_en')->nullable();
            $table->foreignId('aviso_documento_id')->nullable()->constrained('solicitud_interna_documentos')->nullOnDelete();
            $table->timestamp('pago_confirmado_en')->nullable();
            $table->foreignId('pago_confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referencia_pago', 120)->nullable();
            $table->timestamp('baja_ejecutada_en')->nullable();
            $table->timestamp('expediente_cerrado_en')->nullable();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'estado'], 'cierres_laborales_colaborador_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_laborales');
        Schema::dropIfExists('evaluaciones_periodo_prueba');
        Schema::dropIfExists('contratos_laborales');
    }
};
