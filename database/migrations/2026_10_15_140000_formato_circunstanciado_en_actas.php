<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Acta administrativa circunstanciada (docs/acta/ACTA ADMINISTRATIVA
| CIRCUNSTANCIADA.docx): captura estructurada de las secciones del formato
| (incidencia, intervinientes, testigos de hechos y de asistencia,
| manifestaciones, incidencias, cierre, entrega de copia y control
| documental) en `circunstanciada`. `formato` distingue las actas nuevas de
| las históricas, que se siguen leyendo igual (nada se migra ni se borra).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actas_administrativas', function (Blueprint $table): void {
            $table->string('formato', 30)->nullable()->after('tipo');
            $table->json('circunstanciada')->nullable()->after('declaraciones');
        });

        Schema::table('acta_anexos', function (Blueprint $table): void {
            $table->string('fecha_identificacion', 160)->nullable()->after('descripcion');
            $table->string('relacion_hechos', 500)->nullable()->after('fecha_identificacion');
        });
    }

    public function down(): void
    {
        Schema::table('acta_anexos', function (Blueprint $table): void {
            $table->dropColumn(['fecha_identificacion', 'relacion_hechos']);
        });

        Schema::table('actas_administrativas', function (Blueprint $table): void {
            $table->dropColumn(['formato', 'circunstanciada']);
        });
    }
};
