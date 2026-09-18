<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz comercial / territorial (docs/HEADCOUNT_Y_VACANTES.md): árbol
 * MATRIZ -> REGIÓN -> ZONA -> RUTA, distinto del organigrama de puestos y
 * distinto de headcount/vacantes (esos siguen viviendo en `sucursales` +
 * `headcount_targets`, a nivel zona/sucursal, no por ruta individual — el
 * Excel real de headcount no trae ese detalle). Una "zona" de la matriz
 * referencia la `Sucursal` real correspondiente (`sucursal_id`); una "ruta"
 * puede tener un gestor asignado (`responsable_user_id`) para saber si está
 * cubierta, sin que eso cambie el cálculo de headcount por sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodos_comerciales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('nodos_comerciales')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('nombre');
            $table->string('clave')->nullable();
            $table->string('region')->nullable();
            $table->boolean('activa')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('responsable_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsable_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'orden']);
            $table->index('tipo');
            $table->index('activa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodos_comerciales');
    }
};
