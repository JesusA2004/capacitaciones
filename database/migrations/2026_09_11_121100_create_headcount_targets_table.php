<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantilla autorizada por sucursal+puesto (docs/HEADCOUNT_Y_VACANTES.md).
 * Deliberadamente NO guarda "plantilla actual": esa siempre se calcula en
 * vivo contando colaboradores activos (HeadcountService), nunca se importa
 * ni se captura a mano — es la regla central de este modulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('headcount_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->constrained('puestos')->cascadeOnDelete();
            $table->string('region', 20)->nullable();
            $table->unsignedInteger('plantilla_autorizada');
            $table->string('fuente', 30)->default('importado');
            $table->date('fecha_corte');
            $table->boolean('editable')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sucursal_id', 'puesto_id'], 'headcount_targets_sucursal_puesto_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headcount_targets');
    }
};
