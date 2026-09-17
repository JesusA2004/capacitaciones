<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vacantes', function (Blueprint $table) {
            // Presupuesto mensual de la plaza, opcional: alimenta los KPIs de
            // costo de contratación en Vacantes/Candidatos (ver VacanteController::kpis()).
            $table->decimal('sueldo_mensual', 10, 2)->nullable()->after('plazas_disponibles');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vacantes', function (Blueprint $table) {
            $table->dropColumn('sueldo_mensual');
        });
    }
};
