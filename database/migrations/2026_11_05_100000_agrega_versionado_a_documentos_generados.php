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
        // Idempotente: en MariaDB/MyISAM una corrida interrumpida deja columnas
        // creadas sin registrar la migración; se agrega solo lo que falta.
        Schema::table('generated_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('generated_documents', 'huella_datos')) {
                $table->char('huella_datos', 64)->nullable()->after('checksum');
            }

            if (! Schema::hasColumn('generated_documents', 'correcciones')) {
                $table->json('correcciones')->nullable()->after('huella_datos');
            }

            if (! Schema::hasColumn('generated_documents', 'tipo_emision')) {
                $table->string('tipo_emision', 20)->nullable()->after('correcciones');
            }

            if (! Schema::hasIndex('generated_documents', 'generated_documents_versiones_index')) {
                // Sin documentable_type (varchar 255): con él la llave excede
                // los 1000 bytes de MyISAM; documentable_id ya es selectivo.
                $table->index(['documentable_id', 'clave_plantilla', 'id'], 'generated_documents_versiones_index');
            }
        });

        if (! Schema::hasColumn('baja_documentos', 'generated_document_id')) {
            Schema::table('baja_documentos', function (Blueprint $table): void {
                $table->foreignId('generated_document_id')->nullable()->after('documento_requerido_id')->constrained('generated_documents')->nullOnDelete();
            });
        }
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
