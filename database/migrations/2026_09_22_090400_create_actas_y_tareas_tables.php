<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actas y documentos administrativos + bandeja de trabajo (tareas_rh).
 * Ver docs/backend-rh-completion.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actas_administrativas', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 20)->nullable()->unique();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('estado', 20)->default('borrador');
            $table->date('fecha');
            $table->string('hora', 5)->nullable();
            $table->string('lugar')->nullable();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->longText('hechos');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('testigos')->nullable();
            $table->json('declaraciones')->nullable();
            $table->boolean('negativa_firma')->default(false);
            $table->text('motivo_negativa')->nullable();
            $table->json('seguimiento')->nullable();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['colaborador_id', 'tipo'], 'actas_colaborador_tipo_idx');
        });

        Schema::create('acta_anexos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acta_administrativa_id')->constrained('actas_administrativas')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('descripcion')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tareas_rh', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 40);
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('prioridad', 10)->default('media');
            $table->nullableMorphs('relacionado', 'tareas_rh_relacionado_idx');
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            // Destinatario: una cuenta concreta (jefe, colaborador) o
            // "quien tenga este permiso dentro de su alcance" (RH).
            $table->foreignId('asignado_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('asignado_permiso', 80)->nullable();
            $table->string('accion', 60)->nullable();
            $table->date('vence_en')->nullable();
            $table->string('clave', 190);
            // Solo lleva valor mientras la tarea está abierta: el índice
            // único impide duplicar un pendiente abierto (scheduler/jobs
            // repetidos), pero permite recrearlo una vez resuelto.
            $table->string('clave_abierta', 190)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resuelta_en')->nullable();
            $table->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('datos')->nullable();
            $table->timestamps();

            $table->index(['asignado_user_id', 'resuelta_en'], 'tareas_rh_usuario_resuelta_idx');
            $table->index(['asignado_permiso', 'resuelta_en'], 'tareas_rh_permiso_resuelta_idx');
            $table->index('clave');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tareas_rh');
        Schema::dropIfExists('acta_anexos');
        Schema::dropIfExists('actas_administrativas');
    }
};
