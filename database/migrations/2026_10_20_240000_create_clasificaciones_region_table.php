<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lado del organigrama comercial (Región Q1 o Región Q3 de la matriz) al que
 * pertenece un Gerente de Sucursal, CLASIFICADO por RH con un clic. Sin fila
 * aquí el gerente se muestra «Por clasificar» (su jefe directo sigue saliendo,
 * de forma provisional, de la región de su sucursal en la matriz).
 * Ver App\Services\Organigrama\ClasificacionRegionService y docs/ORGANIGRAMA.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clasificaciones_region', function (Blueprint $table): void {
            $table->id();
            // Una clasificación vigente por persona (reclasificar la actualiza;
            // el historial queda en activity_log 'organigrama').
            $table->foreignId('colaborador_id')->unique()->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('nodos_comerciales')->cascadeOnDelete();
            $table->foreignId('clasificada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('region_id', 'clasificaciones_region_region_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clasificaciones_region');
    }
};
