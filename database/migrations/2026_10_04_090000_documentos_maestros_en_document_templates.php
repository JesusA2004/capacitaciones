<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documento maestro jurídico (ver docs/MOTOR_DOCUMENTOS_MAESTROS.md): la
 * misma tabla document_templates representa ahora tres cosas distintas que
 * antes se confundían —
 *
 *   ORIGINAL  archivo exacto entregado por Jurídico (original_*), inmutable;
 *   MASTER    copia técnica procesable que prepara el sistema (disk/path +
 *             master_hash), con su mapping de campos;
 *   OUTPUT    cada instancia generada (generated_documents).
 *
 * Varias variantes por puesto comparten la misma clave (contrato_capacitacion
 * de Gestor, Gerente, Subgerente…): la versión se numera por FAMILIA, no por
 * clave. Las plantillas existentes conservan su versión (familia = clave).
 *
 * Todo aditivo y re-ejecutable (MyISAM no revierte DDL parcial).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_templates', 'familia')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->string('familia', 120)->nullable()->after('clave')->index();
                $table->json('grupos_puesto')->nullable()->after('puesto_id');
                $table->string('proceso', 40)->nullable()->after('categoria');
                $table->string('evento', 60)->nullable()->after('proceso');
                $table->string('causa', 40)->nullable()->after('evento');
                $table->string('original_disk', 40)->nullable();
                $table->string('original_path')->nullable();
                $table->string('original_hash', 64)->nullable()->index();
                $table->string('original_nombre')->nullable();
                $table->string('master_hash', 64)->nullable();
                $table->json('mapping')->nullable();
                $table->json('analisis')->nullable();
                $table->string('estado_master', 30)->nullable();
                $table->boolean('operativo')->default(true);
                $table->boolean('requiere_envio_corporativo')->default(false);
                $table->unsignedTinyInteger('cantidad_testigos')->default(0);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->unsignedSmallInteger('prioridad_especificidad')->default(0);
                $table->json('fuentes')->nullable();
                $table->text('observaciones')->nullable();
                $table->timestamp('ultima_prueba_en')->nullable();
                $table->foreignId('ultima_prueba_por')->nullable()->constrained('users')->nullOnDelete();
                $table->json('ultima_prueba_resultado')->nullable();
            });
        }

        DB::table('document_templates')->whereNull('familia')->whereNotNull('clave')->update(['familia' => DB::raw('clave')]);

        if ($this->tieneIndice('document_templates', 'document_templates_clave_version_unique')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->dropUnique('document_templates_clave_version_unique');
            });
        }

        if (! $this->tieneIndice('document_templates', 'doc_templates_familia_version_unique')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->unique(['familia', 'version'], 'doc_templates_familia_version_unique');
            });
        }

        if (! Schema::hasColumn('generated_documents', 'master_familia')) {
            Schema::table('generated_documents', function (Blueprint $table): void {
                $table->string('master_familia', 120)->nullable()->after('version_plantilla');
                $table->string('master_hash', 64)->nullable()->after('master_familia');
                $table->string('proceso', 40)->nullable()->after('master_hash');
                $table->string('fidelidad', 20)->nullable()->after('checksum');
                $table->string('docx_path')->nullable()->after('fidelidad');
                $table->boolean('requiere_envio_corporativo')->default(false)->after('requiere_testigos');
                $table->unsignedInteger('descargas')->default(0);
                $table->timestamp('ultima_descarga_en')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('generated_documents', 'master_familia')) {
            Schema::table('generated_documents', function (Blueprint $table): void {
                $table->dropColumn(['master_familia', 'master_hash', 'proceso', 'fidelidad', 'docx_path', 'requiere_envio_corporativo', 'descargas', 'ultima_descarga_en']);
            });
        }

        if ($this->tieneIndice('document_templates', 'doc_templates_familia_version_unique')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->dropUnique('doc_templates_familia_version_unique');
            });
        }

        if (Schema::hasColumn('document_templates', 'familia')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('ultima_prueba_por');
                $table->dropColumn([
                    'familia', 'grupos_puesto', 'proceso', 'evento', 'causa', 'original_disk', 'original_path', 'original_hash',
                    'original_nombre', 'master_hash', 'mapping', 'analisis', 'estado_master', 'operativo', 'requiere_envio_corporativo',
                    'cantidad_testigos', 'orden', 'prioridad_especificidad', 'fuentes', 'observaciones', 'ultima_prueba_en', 'ultima_prueba_resultado',
                ]);
            });
        }
    }

    private function tieneIndice(string $tabla, string $indice): bool
    {
        return collect(Schema::getIndexes($tabla))->contains(fn (array $i): bool => $i['name'] === $indice);
    }
};
