<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue las corridas de la migración inicial de expedientes
 * («migracion», RH → Expedientes) de la «Carga inicial PEOPLE» maestra
 * («carga_inicial», Configuración → Importar datos). Comparten tabla y
 * motor (PlanificadorMigracion / EjecutorMigracion), pero cada pantalla
 * lista solo su propio historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migraciones_expedientes', function (Blueprint $table): void {
            $table->string('tipo', 30)->default('migracion')->index();
        });
    }

    public function down(): void
    {
        Schema::table('migraciones_expedientes', function (Blueprint $table): void {
            $table->dropIndex(['tipo']);
            $table->dropColumn('tipo');
        });
    }
};
