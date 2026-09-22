<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detalle de conceptos (finiquito y recibo interno semanal), pago del
 * finiquito, autorización de préstamos y vistos buenos jerárquicos de
 * solicitudes. Ver docs/backend-rh-completion.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->decimal('total_percepciones', 10, 2)->default(0);
            $table->decimal('total_deducciones', 10, 2)->default(0);
            $table->decimal('neto', 10, 2)->default(0);
            $table->timestamp('pagado_en')->nullable();
            $table->foreignId('pago_confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referencia_pago', 120)->nullable();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
        });

        Schema::create('finiquito_conceptos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finiquito_calculo_id')->constrained('finiquito_calculos')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('concepto', 150);
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('importe', 10, 2);
            $table->text('observaciones')->nullable();
            $table->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->string('folio', 30)->nullable()->unique();
            $table->string('tipo_periodo', 20)->nullable();
            $table->unsignedSmallInteger('ejercicio')->nullable();
            $table->unsignedSmallInteger('numero_periodo')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('lote_importacion', 40)->nullable()->index();
            $table->string('checksum', 64)->nullable();
        });

        Schema::create('recibo_nomina_conceptos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recibo_nomina_id')->constrained('recibos_nomina')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('concepto', 150);
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('importe', 10, 2);
            $table->text('observaciones')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::table('prestamos', function (Blueprint $table): void {
            $table->decimal('monto_solicitado', 10, 2)->nullable();
            $table->unsignedSmallInteger('plazo_solicitado')->nullable();
            $table->text('motivo')->nullable();
            $table->date('fecha_solicitud')->nullable();
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('autorizado_en')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('contrato_documento_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->foreignId('pagare_documento_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->timestamp('resguardado_en')->nullable();
            $table->foreignId('resguardado_por')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('solicitud_aprobaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_interna_id')->constrained('solicitudes_internas')->cascadeOnDelete();
            $table->string('nivel', 30);
            $table->string('decision', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comentario')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Una sola decisión por nivel: evita vistos buenos duplicados por
            // doble clic o dos aprobadores concurrentes.
            $table->unique(['solicitud_interna_id', 'nivel'], 'solicitud_aprobaciones_nivel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_aprobaciones');

        Schema::table('prestamos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('autorizado_por');
            $table->dropConstrainedForeignId('contrato_documento_id');
            $table->dropConstrainedForeignId('pagare_documento_id');
            $table->dropConstrainedForeignId('resguardado_por');
            $table->dropColumn(['monto_solicitado', 'plazo_solicitado', 'motivo', 'fecha_solicitud', 'autorizado_en', 'observaciones', 'resguardado_en']);
        });

        Schema::dropIfExists('recibo_nomina_conceptos');

        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->dropUnique(['folio']);
            $table->dropIndex(['lote_importacion']);
            $table->dropColumn(['folio', 'tipo_periodo', 'ejercicio', 'numero_periodo', 'observaciones', 'lote_importacion', 'checksum']);
        });

        Schema::dropIfExists('finiquito_conceptos');

        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pago_confirmado_por');
            $table->dropConstrainedForeignId('generated_document_id');
            $table->dropColumn(['total_percepciones', 'total_deducciones', 'neto', 'pagado_en', 'referencia_pago']);
        });
    }
};
