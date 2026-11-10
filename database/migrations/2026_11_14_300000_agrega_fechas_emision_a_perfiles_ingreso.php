<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de emisión que RH elige para la Solicitud de empleo y para el
 * Estudio socioeconómico (independientes; docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md).
 * Sin fecha elegida el documento sale con la fecha del día en que se genera,
 * como hasta hoy. Solo AGREGA columnas: nada existente se reescribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaborador_perfiles_ingreso', function (Blueprint $table) {
            if (! Schema::hasColumn('colaborador_perfiles_ingreso', 'fecha_emision_solicitud')) {
                $table->date('fecha_emision_solicitud')->nullable()->after('secciones');
            }

            if (! Schema::hasColumn('colaborador_perfiles_ingreso', 'fecha_emision_estudio')) {
                $table->date('fecha_emision_estudio')->nullable()->after('fecha_emision_solicitud');
            }
        });
    }

    public function down(): void
    {
        Schema::table('colaborador_perfiles_ingreso', function (Blueprint $table) {
            foreach (['fecha_emision_estudio', 'fecha_emision_solicitud'] as $columna) {
                if (Schema::hasColumn('colaborador_perfiles_ingreso', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
