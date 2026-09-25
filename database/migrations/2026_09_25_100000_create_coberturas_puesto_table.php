<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coberturas temporales de un puesto: un colaborador actúa en un puesto de
 * OTRA sucursal (o región) sin dejar el suyo — p. ej. el gerente de Córdoba
 * cubre la gerencia de Cuernavaca mientras se contrata y capacita al nuevo.
 * Nunca cambia `colaboradores.puesto_id`: el puesto titular se conserva.
 * Ver App\Services\Organigrama\CoberturaPuestoService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coberturas_puesto', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('puesto_id')->constrained('puestos')->cascadeOnDelete();
            // Ámbito cubierto: una sucursal (gerente, subgerente…) o una
            // región de la matriz comercial (gerente regional).
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('nodos_comerciales')->nullOnDelete();
            $table->string('motivo', 30);
            $table->text('nota')->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activa')->default(true);
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['activa', 'puesto_id', 'sucursal_id'], 'coberturas_puesto_activa_puesto_sucursal_idx');
            $table->index(['activa', 'puesto_id', 'region_id'], 'coberturas_puesto_activa_puesto_region_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coberturas_puesto');
    }
};
