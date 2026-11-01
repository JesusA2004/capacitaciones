<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo uniforme de documentos generados (docs/DOCUMENTOS_VERSIONES.md). Aditiva:
 * no toca registros previos. Un documento anterior sin huella se trata como «sin
 * datos para comparar» y se puede sustituir con motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->char('huella_datos', 64)->nullable()->after('checksum');
            $table->json('correcciones')->nullable()->after('huella_datos');
            $table->string('tipo_emision', 20)->nullable()->after('correcciones');
            $table->index(['documentable_type', 'documentable_id', 'clave_plantilla', 'id'], 'generated_documents_versiones_index');
        });

        Schema::table('baja_documentos', function (Blueprint $table): void {
            $table->foreignId('generated_document_id')->nullable()->after('documento_requerido_id')->constrained('generated_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('baja_documentos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('generated_document_id');
        });

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropIndex('generated_documents_versiones_index');
            $table->dropColumn(['huella_datos', 'correcciones', 'tipo_emision']);
        });
    }
};
