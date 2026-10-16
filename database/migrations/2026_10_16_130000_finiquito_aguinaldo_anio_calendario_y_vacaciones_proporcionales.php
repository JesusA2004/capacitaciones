<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finiquito conforme a la LFT:
 *  - dias_trabajados_anio: días trabajados en el AÑO CALENDARIO de la baja
 *    (del 1 de enero —o la fecha de ingreso si fue después— a la fecha de
 *    baja, inclusive). Es la base del aguinaldo proporcional (art. 87);
 *    antes se usaban por error los días desde el último aniversario.
 *  - vacaciones_proporcionales: días de vacaciones proporcionales del año
 *    de servicio en curso (art. 79), que se suman al saldo pendiente.
 *
 * Nullable/0 para no alterar finiquitos ya calculados o entregados: sus
 * montos guardados siguen siendo los que se calcularon en su momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_trabajados_anio')->nullable()->after('dias_trabajados_periodo');
            $table->decimal('vacaciones_proporcionales', 6, 2)->default(0)->after('vacaciones_pendientes');
        });
    }

    public function down(): void
    {
        Schema::table('finiquito_calculos', function (Blueprint $table): void {
            $table->dropColumn(['dias_trabajados_anio', 'vacaciones_proporcionales']);
        });
    }
};
