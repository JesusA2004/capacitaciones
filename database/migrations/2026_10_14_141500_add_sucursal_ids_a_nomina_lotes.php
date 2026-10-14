<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un lote semanal puede prepararse acotado a ciertas sucursales
     * (App\Services\Nomina\LoteNominaService::preparar, $sucursalIds). Antes
     * no se persistía: al recalcular() el lote se recalculaba como si
     * hubiera sido "todas las sucursales", perdiendo el filtro original. Null
     * = sin filtro (todas las sucursales del grupo de pago correspondiente).
     */
    public function up(): void
    {
        Schema::table('nomina_lotes', function (Blueprint $table): void {
            $table->json('sucursal_ids')->nullable()->after('origen');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_lotes', function (Blueprint $table): void {
            $table->dropColumn('sucursal_ids');
        });
    }
};
