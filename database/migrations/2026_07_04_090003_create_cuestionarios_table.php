<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuestionarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leccion_id')->unique()->constrained('lecciones')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('instrucciones')->nullable();
            $table->unsignedTinyInteger('calificacion_minima')->default(80);
            $table->unsignedInteger('intentos_maximos')->nullable();
            $table->unsignedInteger('tiempo_limite_minutos')->nullable();
            $table->unsignedInteger('tolerancia_segundos')->default(30);
            $table->boolean('aleatorizar_preguntas')->default(false);
            $table->boolean('aleatorizar_opciones')->default(false);
            $table->boolean('mostrar_retroalimentacion')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuestionarios');
    }
};
