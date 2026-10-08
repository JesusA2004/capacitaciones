<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formatos oficiales de RH (docs/formatosRH/*.docx) y lotes de nómina:
 *
 *  - empresas: registro patronal IMSS y C.P. de expedición, que imprimen
 *    los encabezados oficiales (recibo, finiquito, permiso).
 *  - recibos/finiquitos: clave de concepto (001 Sueldo, 101 ISR…) y días
 *    del periodo del recibo oficial.
 *  - nomina_lotes: histórico del lote PREPARAR → REVISAR → EMITIR, que no
 *    depende del archivo temporal importado.
 *  - finiquito_ajustes: valor calculado vs. valor final autorizado de cada
 *    concepto automático, con motivo, usuario y fecha.
 *  - solicitudes_internas: datos del Formato de Permiso (modalidad, goce y
 *    causal del permiso especial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->string('registro_patronal', 20)->nullable()->after('rfc');
            $table->string('codigo_postal_fiscal', 10)->nullable()->after('domicilio_fiscal');
        });

        Schema::create('nomina_lotes', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 40)->unique();
            $table->string('periodicidad', 20);
            $table->date('periodo_inicio');
            $table->date('periodo_fin');
            $table->date('fecha_pago');
            $table->unsignedSmallInteger('numero_nomina')->nullable();
            $table->string('origen', 20);
            $table->string('archivo_nombre')->nullable();
            $table->string('estado', 20);
            $table->unsignedInteger('esperados')->default(0);
            $table->unsignedInteger('preparados')->default(0);
            $table->json('errores')->nullable();
            $table->json('advertencias')->nullable();
            $table->decimal('total_percepciones', 14, 2)->default(0);
            $table->decimal('total_deducciones', 14, 2)->default(0);
            $table->decimal('total_neto', 14, 2)->default(0);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('emitido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emitido_at')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_at')->nullable();
            $table->string('motivo_cancelacion', 500)->nullable();
            $table->timestamps();

            $table->index(['periodicidad', 'periodo_inicio', 'periodo_fin'], 'nomina_lotes_periodo_idx');
            $table->index('estado');
        });

        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->foreignId('nomina_lote_id')->nullable()->after('lote_importacion')->constrained('nomina_lotes')->nullOnDelete();
            $table->decimal('dias_pagados', 6, 2)->nullable()->after('numero_periodo');
            $table->decimal('dias_falta', 6, 2)->nullable()->after('dias_pagados');
            $table->decimal('dias_incapacidad', 6, 2)->nullable()->after('dias_falta');
            $table->json('advertencias')->nullable()->after('observaciones');
            $table->timestamp('cancelado_at')->nullable()->after('emitido_at');
            $table->foreignId('cancelado_por')->nullable()->after('cancelado_at')->constrained('users')->nullOnDelete();
            $table->foreignId('emitido_por')->nullable()->after('generado_por')->constrained('users')->nullOnDelete();
        });

        Schema::table('recibo_nomina_conceptos', function (Blueprint $table): void {
            $table->string('clave', 10)->nullable()->after('tipo');
        });

        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->decimal('isr_retenido', 12, 2)->default(0)->after('adeudos');
        });

        Schema::table('finiquito_conceptos', function (Blueprint $table): void {
            $table->string('clave', 10)->nullable()->after('tipo');
        });

        Schema::create('finiquito_ajustes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finiquito_calculo_id')->constrained('finiquito_calculos')->cascadeOnDelete();
            $table->string('concepto_clave', 40);
            $table->string('concepto', 150);
            $table->decimal('valor_calculado', 12, 2);
            $table->decimal('valor_final', 12, 2);
            $table->decimal('ajuste', 12, 2);
            $table->string('motivo', 500);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['finiquito_calculo_id', 'concepto_clave'], 'finiquito_ajustes_concepto_idx');
        });

        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->string('permiso_tipo', 30)->nullable()->after('modalidad_permiso');
            $table->string('permiso_goce', 20)->nullable()->after('permiso_tipo');
            $table->string('permiso_causal', 20)->nullable()->after('permiso_goce');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->dropColumn(['permiso_tipo', 'permiso_goce', 'permiso_causal']);
        });

        Schema::dropIfExists('finiquito_ajustes');

        Schema::table('finiquito_conceptos', function (Blueprint $table): void {
            $table->dropColumn('clave');
        });

        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->dropColumn('isr_retenido');
        });

        Schema::table('recibo_nomina_conceptos', function (Blueprint $table): void {
            $table->dropColumn('clave');
        });

        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('nomina_lote_id');
            $table->dropConstrainedForeignId('cancelado_por');
            $table->dropConstrainedForeignId('emitido_por');
            $table->dropColumn(['dias_pagados', 'dias_falta', 'dias_incapacidad', 'advertencias', 'cancelado_at']);
        });

        Schema::dropIfExists('nomina_lotes');

        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn(['registro_patronal', 'codigo_postal_fiscal']);
        });
    }
};
