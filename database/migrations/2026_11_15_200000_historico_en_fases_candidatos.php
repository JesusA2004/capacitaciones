<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantilla histórica en el recorrido del candidato (2026-11-12):
 *
 *  - candidatos.historico: el registro NO viene de un proceso de reclutamiento
 *    en PEOPLE; lo crea `people:ubicar-historico-en-fases` para que un
 *    colaborador que ya laboraba antes del sistema aparezca en su fase. No
 *    cuenta en los indicadores de reclutamiento.
 *  - colaboradores.contrato_indeterminado_fisico_desde: hecho explícito «su
 *    contrato por tiempo indeterminado existe en papel (no lo generó PEOPLE)»,
 *    con la fecha desde la que rige (fin del periodo de prueba + 1 día). Lo
 *    lee FaseContratadoService para la columna «Contrato indeterminado»;
 *    nunca se fabrica un documento generado.
 *
 * Aditiva: no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('candidatos', 'historico')) {
            Schema::table('candidatos', function (Blueprint $table): void {
                $table->boolean('historico')->default(false)->after('espontaneo')->index();
            });
        }

        if (! Schema::hasColumn('colaboradores', 'contrato_indeterminado_fisico_desde')) {
            Schema::table('colaboradores', function (Blueprint $table): void {
                $table->date('contrato_indeterminado_fisico_desde')->nullable()->after('periodo_prueba_fin');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('candidatos', 'historico')) {
            Schema::table('candidatos', function (Blueprint $table): void {
                $table->dropIndex(['historico']);
                $table->dropColumn('historico');
            });
        }

        if (Schema::hasColumn('colaboradores', 'contrato_indeterminado_fisico_desde')) {
            Schema::table('colaboradores', function (Blueprint $table): void {
                $table->dropColumn('contrato_indeterminado_fisico_desde');
            });
        }
    }
};
