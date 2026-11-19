<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bajas inmediatas vs. programadas (docs/BAJAS_Y_CAUSALES.md):
 *  - modalidad_baja: App\Enums\ModalidadBaja (las existentes = «inmediata»).
 *  - avisar_colaborador: si la persona recibe el aviso de su baja (por
 *    defecto no, como hasta ahora).
 *  - programada_aplicada_en: cuándo se ejecutó la programada (accesos
 *    suspendidos y baja concluida); null = aún pendiente. Idempotencia del
 *    comando diario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cierres_laborales', function (Blueprint $table) {
            if (! Schema::hasColumn('cierres_laborales', 'modalidad_baja')) {
                $table->string('modalidad_baja', 20)->default('inmediata')->after('fecha_efectiva');
            }

            if (! Schema::hasColumn('cierres_laborales', 'avisar_colaborador')) {
                $table->boolean('avisar_colaborador')->default(false)->after('modalidad_baja');
            }

            if (! Schema::hasColumn('cierres_laborales', 'programada_aplicada_en')) {
                $table->timestamp('programada_aplicada_en')->nullable()->after('avisar_colaborador');
            }
        });

        Schema::table('cierres_laborales', function (Blueprint $table) {
            $table->index(['modalidad_baja', 'programada_aplicada_en', 'fecha_efectiva'], 'cierres_modalidad_programada_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cierres_laborales', function (Blueprint $table) {
            $table->dropIndex('cierres_modalidad_programada_idx');
            $table->dropColumn(['modalidad_baja', 'avisar_colaborador', 'programada_aplicada_en']);
        });
    }
};
