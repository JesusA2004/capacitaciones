<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faltas sin justificar y retardos del periodo (dato de RH) que el jefe
 * inmediato ve al calificar «Asistencia y puntualidad» del FO-RH-EPP-001 y
 * que se imprimen en la ficha del PDF oficial. Son opcionales: si RH/PEOPLE
 * no los tiene, la casilla queda en blanco como en el formato impreso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluaciones_periodo_prueba', function (Blueprint $table): void {
            $table->unsignedSmallInteger('faltas_injustificadas')->nullable()->after('filtros');
            $table->unsignedSmallInteger('retardos')->nullable()->after('faltas_injustificadas');
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones_periodo_prueba', function (Blueprint $table): void {
            $table->dropColumn(['faltas_injustificadas', 'retardos']);
        });
    }
};
