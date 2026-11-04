<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recepción documental RH (docs/RECEPCION_DOCUMENTAL_RH.md): conciliación
 * MENSUAL de los originales firmados que RH recibe por paquetería.
 *
 *  - recepcion_documental_cortes: un corte por mes calendario (periodo
 *    «AAAA-MM» único).
 *  - recepcion_documental_renglones: lo que el sistema ESPERA recibir en ese
 *    corte, calculado desde eventos reales (alta, baja, recibo emitido…),
 *    con snapshot mínimo que no cambia aunque después cambien los datos del
 *    colaborador. El índice único hace idempotente la materialización.
 *  - recepcion_documental_pdfs: cada PDF operativo generado (versionado, nunca
 *    se sobreescribe el anterior).
 *
 * No reutiliza el flujo legacy de envío/recepción a corporativo
 * (seguimientos_documento_fisico): solo REFERENCIA el documento digital.
 * Solo crea tablas nuevas: no toca ningún registro existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recepcion_documental_cortes')) {
            Schema::create('recepcion_documental_cortes', function (Blueprint $table) {
                $table->id();
                $table->string('periodo', 7);
                $table->unsignedSmallInteger('anio');
                $table->unsignedTinyInteger('mes');
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->string('estado', 30)->default('en_preparacion');
                $table->timestamp('generado_en')->nullable();
                $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('materializado_en')->nullable();
                $table->timestamp('cerrado_en')->nullable();
                $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->text('observaciones_cierre')->nullable();
                $table->unsignedInteger('reabierto_conteo')->default(0);
                $table->timestamp('reabierto_en')->nullable();
                $table->foreignId('reabierto_por')->nullable()->constrained('users')->nullOnDelete();
                $table->text('motivo_reapertura')->nullable();
                $table->timestamps();

                $table->unique('periodo', 'rec_doc_corte_periodo_unico');
                $table->index(['anio', 'mes'], 'rec_doc_corte_anio_mes_idx');
            });
        }

        if (! Schema::hasTable('recepcion_documental_renglones')) {
            Schema::create('recepcion_documental_renglones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('corte_id')->constrained('recepcion_documental_cortes')->cascadeOnDelete();
                $table->string('regla', 30);
                $table->string('tipo_documento', 60);
                $table->string('tipo_etiqueta', 160);
                $table->string('origen_type', 80);
                $table->unsignedBigInteger('origen_id');
                $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
                $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
                $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
                // Snapshot mínimo del evento (no cambia al editar al colaborador).
                $table->string('colaborador_nombre', 200)->nullable();
                $table->string('numero_empleado', 40)->nullable();
                $table->string('puesto_nombre', 160)->nullable();
                $table->string('sucursal_nombre', 160)->nullable();
                $table->date('fecha_evento')->nullable();
                $table->string('evento_descripcion', 255)->nullable();
                $table->unsignedSmallInteger('cantidad_esperada')->default(1);
                $table->unsignedSmallInteger('cantidad_recibida')->default(0);
                $table->string('estado', 30)->default('pendiente');
                $table->text('justificacion')->nullable();
                // true = el sistema lo pasó a «No aplica» porque el evento de
                // origen se canceló o ya no cae en el periodo.
                $table->boolean('no_aplica_automatico')->default(false);
                $table->timestamp('recibido_en')->nullable();
                $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
                $table->text('observaciones')->nullable();
                $table->boolean('incidencia')->default(false);
                $table->string('evidencia_path', 500)->nullable();
                $table->string('evidencia_nombre', 255)->nullable();
                $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['corte_id', 'regla', 'origen_type', 'origen_id', 'tipo_documento'], 'rec_doc_renglon_unico');
                $table->index(['corte_id', 'sucursal_id'], 'rec_doc_renglon_corte_sucursal_idx');
                $table->index(['corte_id', 'estado'], 'rec_doc_renglon_corte_estado_idx');
                $table->index(['origen_type', 'origen_id'], 'rec_doc_renglon_origen_idx');
            });
        }

        if (! Schema::hasTable('recepcion_documental_pdfs')) {
            Schema::create('recepcion_documental_pdfs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('corte_id')->constrained('recepcion_documental_cortes')->cascadeOnDelete();
                $table->string('variante', 20);
                $table->unsignedInteger('version');
                $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
                $table->string('disk', 40);
                $table->string('path', 500);
                $table->string('nombre', 255);
                $table->unsignedBigInteger('tamano')->default(0);
                $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generado_en')->nullable();
                $table->timestamps();

                $table->unique(['corte_id', 'variante', 'version'], 'rec_doc_pdf_version_unico');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcion_documental_pdfs');
        Schema::dropIfExists('recepcion_documental_renglones');
        Schema::dropIfExists('recepcion_documental_cortes');
    }
};
