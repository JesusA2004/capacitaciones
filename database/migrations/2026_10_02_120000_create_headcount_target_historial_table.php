<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de la plantilla autorizada (docs/HEADCOUNT_Y_VACANTES.md): cada
 * cambio — captura de RH o importación del Excel — deja quién, cuándo, el
 * valor anterior, el nuevo y el motivo. Nunca se edita ni se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('headcount_target_historial', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('headcount_target_id')->nullable()->constrained('headcount_targets')->nullOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('puesto_id')->constrained('puestos')->cascadeOnDelete();
            $table->unsignedInteger('valor_anterior')->nullable();
            $table->unsignedInteger('valor_nuevo');
            $table->string('fuente', 30);
            $table->string('motivo', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sucursal_id', 'created_at'], 'hc_historial_sucursal_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headcount_target_historial');
    }
};
