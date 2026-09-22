<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor documental general (docs/backend-rh-completion.md):
 *
 * - document_types: categoría documental (subcarpeta del expediente).
 * - employee_documents: origen (carga manual vs. documento generado/escaneado).
 * - document_templates: se reutiliza la tabla de plantillas existente y se
 *   amplía con clave/versión, categoría, motor de render y banderas de
 *   firma digital/impresión/firma física/huella/testigos.
 * - generated_documents: se reutiliza como "documento emitido" y se amplía
 *   con snapshot del payload, checksum, flujo documental y firma digital.
 * - seguimientos_documento_fisico: control del original físico (1:1).
 * - documento_eventos: bitácora de auditoría del flujo (quién/qué/cuándo/IP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->string('categoria', 30)->default('personales')->after('clave');
        });

        Schema::table('employee_documents', function (Blueprint $table): void {
            $table->string('origen', 20)->default('carga')->after('status');
        });

        Schema::table('document_templates', function (Blueprint $table): void {
            $table->string('clave', 60)->nullable()->after('nombre');
            $table->string('categoria', 30)->nullable()->after('tipo');
            $table->string('motor', 20)->default('docx')->after('categoria');
            $table->longText('contenido_html')->nullable();
            $table->foreignId('official_format_id')->nullable()->constrained('official_formats')->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
            $table->boolean('requiere_firma_digital')->default(false);
            $table->boolean('requiere_impresion')->default(false);
            $table->boolean('requiere_firma_fisica')->default(false);
            $table->boolean('requiere_huella')->default(false);
            $table->boolean('requiere_testigos')->default(false);

            $table->string('disk')->nullable()->change();
            $table->string('path')->nullable()->change();
            $table->string('original_name')->nullable()->change();

            $table->unique(['clave', 'version'], 'document_templates_clave_version_unique');
        });

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->nullableMorphs('documentable', 'generated_documents_documentable_idx');
            $table->string('clave_plantilla', 60)->nullable();
            $table->unsignedInteger('version_plantilla')->nullable();
            $table->string('categoria', 30)->nullable();
            $table->string('titulo')->nullable();
            $table->json('payload')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('estado_flujo', 40)->nullable()->index();
            $table->boolean('requiere_firma_digital')->default(false);
            $table->boolean('requiere_impresion')->default(false);
            $table->boolean('requiere_firma_fisica')->default(false);
            $table->boolean('requiere_huella')->default(false);
            $table->boolean('requiere_testigos')->default(false);
            $table->timestamp('firmado_digital_en')->nullable();
            $table->foreignId('firmado_digital_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('firma_digital_ip', 45)->nullable();
            $table->string('firma_digital_user_agent', 500)->nullable();
            $table->string('firma_digital_hash', 64)->nullable();
            $table->text('motivo_cancelacion')->nullable();
        });

        Schema::create('seguimientos_documento_fisico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generated_document_id')->unique()->constrained('generated_documents')->cascadeOnDelete();
            $table->timestamp('impreso_en')->nullable();
            $table->foreignId('impreso_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('firmado_fisico_en')->nullable();
            // Nombre explícito: el autogenerado excede los 64 caracteres de MariaDB.
            $table->foreignId('firma_fisica_registrada_por')->nullable()->constrained('users', indexName: 'seg_doc_fisico_firma_registrada_por_fk')->nullOnDelete();
            $table->boolean('huella_registrada')->default(false);
            $table->json('testigos')->nullable();
            $table->foreignId('sucursal_origen_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->timestamp('enviado_en')->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('paqueteria', 80)->nullable();
            $table->string('numero_guia', 80)->nullable();
            $table->string('comprobante_disk')->nullable();
            $table->string('comprobante_path')->nullable();
            $table->string('comprobante_nombre')->nullable();
            $table->timestamp('recibido_en')->nullable();
            $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escaneado_en')->nullable();
            $table->foreignId('escaneado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('documento_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generated_document_id')->constrained('generated_documents')->cascadeOnDelete();
            $table->string('accion', 40);
            $table->string('estado_anterior', 40)->nullable();
            $table->string('estado_nuevo', 40)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->text('observaciones')->nullable();
            $table->json('datos')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['generated_document_id', 'created_at'], 'documento_eventos_documento_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_eventos');
        Schema::dropIfExists('seguimientos_documento_fisico');

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropMorphs('documentable', 'generated_documents_documentable_idx');
            $table->dropConstrainedForeignId('firmado_digital_por');
            $table->dropIndex(['estado_flujo']);
            $table->dropColumn([
                'clave_plantilla', 'version_plantilla', 'categoria', 'titulo', 'payload', 'checksum', 'estado_flujo',
                'requiere_firma_digital', 'requiere_impresion', 'requiere_firma_fisica', 'requiere_huella', 'requiere_testigos',
                'firmado_digital_en', 'firma_digital_ip', 'firma_digital_user_agent', 'firma_digital_hash', 'motivo_cancelacion',
            ]);
        });

        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropUnique('document_templates_clave_version_unique');
            $table->dropConstrainedForeignId('official_format_id');
            $table->dropConstrainedForeignId('document_type_id');
            $table->dropColumn([
                'clave', 'categoria', 'motor', 'contenido_html',
                'requiere_firma_digital', 'requiere_impresion', 'requiere_firma_fisica', 'requiere_huella', 'requiere_testigos',
            ]);
        });

        Schema::table('employee_documents', function (Blueprint $table): void {
            $table->dropColumn('origen');
        });

        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropColumn('categoria');
        });
    }
};
