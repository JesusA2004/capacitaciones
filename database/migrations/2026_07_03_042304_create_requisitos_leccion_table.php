<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prerequisitos explicitos entre lecciones (grafo), independientes del
     * orden secuencial simple. Permite modelar "la leccion C requiere A y B".
     */
    public function up(): void
    {
        Schema::create('requisitos_leccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leccion_id')->constrained('lecciones')->cascadeOnDelete();
            $table->foreignId('requisito_leccion_id')->constrained('lecciones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['leccion_id', 'requisito_leccion_id'], 'requisitos_leccion_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisitos_leccion');
    }
};
