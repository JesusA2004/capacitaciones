<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respuestas_cuestionario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intento_cuestionario_id')->constrained('intentos_cuestionario')->cascadeOnDelete();
            $table->foreignId('pregunta_id')->constrained('preguntas')->cascadeOnDelete();
            $table->foreignId('opcion_pregunta_id')->nullable()->constrained('opciones_pregunta')->nullOnDelete();
            $table->json('opciones_seleccionadas')->nullable();
            $table->text('respuesta_texto')->nullable();
            $table->boolean('es_correcta')->nullable();
            $table->unsignedInteger('puntos_obtenidos')->nullable();
            $table->timestamps();

            $table->unique(['intento_cuestionario_id', 'pregunta_id'], 'respuestas_cuestionario_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respuestas_cuestionario');
    }
};
