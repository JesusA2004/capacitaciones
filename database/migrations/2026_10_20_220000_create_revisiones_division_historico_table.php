<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editor de hojas del PDF histórico (docs/DIVISION_EXPEDIENTE_HISTORICO.md,
 * «Corregir hojas a mano»).
 *
 *  - revisiones_division_historico: cada vez que RH confirma una corrección
 *    hoja por hoja de una división. Versionada (1, 2, 3…), con el plan
 *    completo (qué hojas forman cada documento y de qué tipo), su hash
 *    (idempotencia), y la auditoría hoja por hoja (de qué clasificación a
 *    cuál se movió cada hoja).
 *  - partes_division_historico: `revision_id` dice en qué revisión nació la
 *    parte (null = la generó el OCR) y `reemplazada_por_revision_id` la
 *    revisión que la dejó sin efecto. Una parte reemplazada NUNCA se borra:
 *    su copia en el NAS y su fila se conservan como evidencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisiones_division_historico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('division_id')->constrained('divisiones_expediente_historico')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('plan');
            $table->string('hash_plan', 64);
            $table->json('cambios');
            $table->json('advertencias')->nullable();
            $table->foreignId('hecha_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['division_id', 'version'], 'rev_div_hist_division_version_uq');
        });

        Schema::table('partes_division_historico', function (Blueprint $table): void {
            $table->foreignId('revision_id')->nullable()->after('division_id')
                ->constrained('revisiones_division_historico', 'id', 'partes_div_hist_revision_fk')->nullOnDelete();
            $table->foreignId('reemplazada_por_revision_id')->nullable()->after('revision_id')
                ->constrained('revisiones_division_historico', 'id', 'partes_div_hist_reemplazada_fk')->nullOnDelete();
            $table->timestamp('reemplazada_en')->nullable()->after('asignado_en');
        });
    }

    public function down(): void
    {
        Schema::table('partes_division_historico', function (Blueprint $table): void {
            $table->dropForeign('partes_div_hist_revision_fk');
            $table->dropForeign('partes_div_hist_reemplazada_fk');
            $table->dropColumn(['revision_id', 'reemplazada_por_revision_id', 'reemplazada_en']);
        });

        Schema::dropIfExists('revisiones_division_historico');
    }
};
