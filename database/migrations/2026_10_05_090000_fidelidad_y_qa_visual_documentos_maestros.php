<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Fidelidad del motor documental (docs/MOTOR_DOCUMENTOS_MAESTROS.md):
|
|  - document_templates: QA visual por versión del master (estado, similitud,
|    páginas original/salida, motor que la validó, reporte), diagnóstico de
|    fuentes, quién/cuándo la activó y la activación excepcional (motivo).
|  - generated_documents: motor y nivel de fidelidad de la conversión, versión
|    del master, DOCX llenado con su hash y revisiones excepcionales de un
|    documento ya firmado (la anterior nunca se toca).
|  - puestos: decisión documental explícita — o tiene grupo documental o se
|    marca "no requiere documentos laborales" con motivo.
|
| Los documentos ya emitidos conservan su `fidelidad` histórica; la nueva
| columna conversion_fidelity solo se llena para los nuevos (los antiguos
| marcados "aproximada" se copian tal cual; los "exacta" quedan sin nivel
| porque no se sabe con qué conversor se hicieron — no se inventa).
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_templates', 'visual_validation_status')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->string('visual_validation_status', 20)->default('pending')->after('estado_master');
                $table->decimal('visual_similarity', 6, 4)->nullable()->after('visual_validation_status');
                $table->unsignedSmallInteger('page_count_original')->nullable()->after('visual_similarity');
                $table->unsignedSmallInteger('page_count_output')->nullable()->after('page_count_original');
                $table->timestamp('visual_checked_at')->nullable()->after('page_count_output');
                $table->string('visual_engine', 30)->nullable()->after('visual_checked_at');
                $table->json('visual_report')->nullable()->after('visual_engine');
                $table->json('diagnostico_fuentes')->nullable()->after('visual_report');
                $table->foreignId('activado_por')->nullable()->after('activo')->constrained('users')->nullOnDelete();
                $table->timestamp('activado_en')->nullable()->after('activado_por');
                $table->text('activacion_excepcional_motivo')->nullable()->after('activado_en');
            });
        }

        if (! Schema::hasColumn('generated_documents', 'conversion_engine')) {
            Schema::table('generated_documents', function (Blueprint $table): void {
                $table->string('conversion_engine', 30)->nullable()->after('fidelidad');
                $table->string('conversion_fidelity', 20)->nullable()->after('conversion_engine');
                $table->unsignedInteger('master_version')->nullable()->after('master_hash');
                $table->string('docx_disk', 40)->nullable()->after('docx_path');
                $table->string('docx_hash', 64)->nullable()->after('docx_disk');
                $table->unsignedSmallInteger('paginas')->nullable()->after('size');
                $table->foreignId('revision_de_id')->nullable()->after('documentable_id')->constrained('generated_documents')->nullOnDelete();
                $table->text('motivo_revision')->nullable()->after('revision_de_id');
            });

            DB::table('generated_documents')->where('fidelidad', 'aproximada')->update(['conversion_fidelity' => 'aproximada', 'conversion_engine' => 'phpword']);
            DB::table('generated_documents')->whereNotNull('docx_path')->whereNull('docx_disk')->update(['docx_disk' => DB::raw('disk')]);
            DB::table('generated_documents')->whereNotNull('master_familia')->whereNull('master_version')->update(['master_version' => DB::raw('version_plantilla')]);
        }

        if (! Schema::hasColumn('puestos', 'no_requiere_documentos_laborales')) {
            Schema::table('puestos', function (Blueprint $table): void {
                $table->boolean('no_requiere_documentos_laborales')->default(false)->after('grupo_documental');
                $table->string('motivo_sin_documentos', 500)->nullable()->after('no_requiere_documentos_laborales');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('puestos', 'no_requiere_documentos_laborales')) {
            Schema::table('puestos', function (Blueprint $table): void {
                $table->dropColumn(['no_requiere_documentos_laborales', 'motivo_sin_documentos']);
            });
        }

        if (Schema::hasColumn('generated_documents', 'conversion_engine')) {
            Schema::table('generated_documents', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('revision_de_id');
                $table->dropColumn(['conversion_engine', 'conversion_fidelity', 'master_version', 'docx_disk', 'docx_hash', 'paginas', 'motivo_revision']);
            });
        }

        if (Schema::hasColumn('document_templates', 'visual_validation_status')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('activado_por');
                $table->dropColumn([
                    'visual_validation_status', 'visual_similarity', 'page_count_original', 'page_count_output',
                    'visual_checked_at', 'visual_engine', 'visual_report', 'diagnostico_fuentes', 'activado_en', 'activacion_excepcional_motivo',
                ]);
            });
        }
    }
};
