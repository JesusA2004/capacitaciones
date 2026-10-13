<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Perfil de puesto (uno por puesto), editado por RH:
| - Perfil del candidato: lo que debe tener quien se postula (escolaridad,
|   experiencia, conocimientos, habilidades, competencias, valores,
|   requisitos).
| - Descripción del puesto: el «deber ser» (objetivo, funciones,
|   responsabilidades, indicadores).
| Las listas se guardan como JSON de textos.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles_puesto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('puesto_id')->unique()->constrained('puestos')->cascadeOnDelete();
            $table->text('objetivo')->nullable();
            $table->json('funciones')->nullable();
            $table->json('responsabilidades')->nullable();
            $table->json('indicadores')->nullable();
            $table->string('escolaridad', 255)->nullable();
            $table->text('experiencia')->nullable();
            $table->json('conocimientos')->nullable();
            $table->json('habilidades')->nullable();
            $table->json('competencias')->nullable();
            $table->json('valores')->nullable();
            $table->json('requisitos')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfiles_puesto');
    }
};
