<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * División del PDF histórico único por checklist
 * (docs/DIVISION_EXPEDIENTE_HISTORICO.md).
 *
 *  - divisiones_expediente_historico: cada corrida sobre un
 *    expedientes_historicos (auditable: hash del PDF leído, versión de
 *    reglas, disponibilidad de OCR, clasificación de CADA página y el
 *    reporte de datos detectados contra el colaborador).
 *  - partes_division_historico: cada parte generada (copia PDF con las
 *    páginas de un tipo del checklist o un tramo «sin clasificar») y, si se
 *    asignó al checklist, el employee_documents creado en revisión.
 *
 * El PDF original nunca se modifica ni se borra: las partes son copias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisiones_expediente_historico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expediente_historico_id')->constrained('expedientes_historicos')->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->string('estado', 20)->default('procesando');
            $table->string('hash_origen', 64)->nullable();
            $table->unsignedInteger('version_reglas')->default(1);
            $table->unsignedInteger('total_paginas')->default(0);
            $table->unsignedInteger('paginas_clasificadas')->default(0);
            $table->unsignedInteger('paginas_sin_clasificar')->default(0);
            $table->unsignedInteger('paginas_sin_texto')->default(0);
            $table->boolean('ocr_disponible')->default(false);
            $table->string('ocr_motor', 120)->nullable();
            $table->json('paginas')->nullable();
            $table->json('datos_detectados')->nullable();
            $table->json('advertencias')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('ejecutado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['expediente_historico_id', 'estado'], 'div_hist_historico_estado_idx');
            $table->index('colaborador_id', 'div_hist_colaborador_idx');
        });

        Schema::create('partes_division_historico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('division_id')->constrained('divisiones_expediente_historico')->restrictOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->restrictOnDelete();
            $table->unsignedInteger('orden')->default(0);
            $table->json('paginas');
            $table->string('confianza', 10)->nullable();
            $table->string('estado', 20);
            $table->string('disk', 40)->nullable();
            $table->string('path', 1000)->nullable();
            $table->string('hash', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('employee_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('asignado_en')->nullable();
            $table->timestamps();

            $table->index(['division_id', 'orden'], 'partes_div_hist_division_orden_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partes_division_historico');
        Schema::dropIfExists('divisiones_expediente_historico');
    }
};
