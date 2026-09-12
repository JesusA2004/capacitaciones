<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puestos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->text('descripcion')->nullable();
            // Menor numero = mayor jerarquia (1 = puesto mas alto de su linea).
            $table->unsignedTinyInteger('nivel_jerarquico')->nullable();
            $table->foreignId('puesto_superior_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('puesto_crecimiento_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->string('tipo_puesto', 30)->nullable();
            $table->string('esquema_comisiones')->nullable();
            $table->boolean('requiere_ruta')->default(false);
            $table->text('responsabilidades')->nullable();
            $table->text('requisitos')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puestos');
    }
};
