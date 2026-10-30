<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Incapacidades y rectificaciones de nómina (Etapa 1, págs. 7-9). Solo
 * crea tablas: las solicitudes históricas de incapacidad no se tocan.
 *
 * `folio_vigente` es el serie y folio normalizado mientras la incapacidad no
 * esté cancelada: el índice único evita duplicados aun con doble envío, y al
 * cancelar queda en null para poder recapturar el folio correctamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incapacidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('tipo', 30);
            $table->string('subtipo_riesgo', 30)->nullable();
            $table->string('secuencia', 20);
            $table->foreignId('incapacidad_inicial_id')->nullable()->constrained('incapacidades')->nullOnDelete();
            $table->string('serie_folio_imss', 40);
            $table->string('folio_vigente', 40)->nullable()->unique('incapacidades_folio_vigente_unique');
            $table->date('fecha_inicio');
            $table->unsignedSmallInteger('dias');
            $table->date('fecha_fin');
            $table->string('riesgo_folio', 20)->nullable()->unique('incapacidades_riesgo_folio_unique');
            $table->date('fecha_evento')->nullable();
            $table->text('descripcion_evento')->nullable();
            $table->date('riesgo_concluido_en')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelada_en')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_cancelacion')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'fecha_inicio'], 'incapacidades_colaborador_inicio_index');
            $table->index(['fecha_inicio', 'fecha_fin'], 'incapacidades_periodo_index');
        });

        Schema::create('incapacidad_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incapacidad_id')->constrained('incapacidades')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->date('fecha_documento')->nullable();
            $table->string('nombre_original');
            $table->string('disco', 40);
            $table->string('ruta', 500);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->foreignId('cargado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reemplaza_id')->nullable()->constrained('incapacidad_documentos')->nullOnDelete();
            $table->text('motivo_reemplazo')->nullable();
            $table->timestamp('reemplazado_en')->nullable();
            $table->timestamps();

            $table->index(['incapacidad_id', 'tipo'], 'incapacidad_documentos_tipo_index');
        });

        Schema::create('nomina_rectificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recibo_nomina_id')->constrained('recibos_nomina')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->string('estado', 20)->default('pendiente');
            $table->decimal('dias_pagados_antes', 6, 2)->nullable();
            $table->decimal('dias_pagados_despues', 6, 2);
            $table->decimal('dias_incapacidad_despues', 6, 2)->default(0);
            $table->decimal('dias_falta_despues', 6, 2)->default(0);
            $table->decimal('neto_antes', 12, 2);
            $table->decimal('importe_sueldo_despues', 12, 2);
            $table->text('motivo');
            $table->text('nota_resolucion')->nullable();
            $table->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelta_en')->nullable();
            $table->timestamps();

            $table->index(['estado', 'colaborador_id'], 'nomina_rectificaciones_estado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_rectificaciones');
        Schema::dropIfExists('incapacidad_documentos');
        Schema::dropIfExists('incapacidades');
    }
};
