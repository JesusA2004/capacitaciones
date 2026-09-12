<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intentos_cuestionario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuestionario_id')->constrained('cuestionarios')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('numero_intento');
            $table->string('estado')->default('en_progreso');
            // Orden fijo de preguntas: array de IDs de `preguntas` en el
            // orden exacto en que se le mostraron a este intento.
            $table->json('orden_preguntas')->nullable();
            // Orden fijo de opciones por pregunta (solo si el cuestionario
            // tiene aleatorizar_opciones activo): mapa pregunta_id => array
            // ordenado de IDs de `opciones_pregunta`.
            $table->json('orden_opciones')->nullable();
            // Puntaje configurado (pivote cuestionario_pregunta.puntos o
            // pregunta.puntos) capturado al iniciar el intento, para que un
            // cambio posterior en la configuración del cuestionario no
            // altere la calificación de intentos ya en curso.
            $table->json('puntaje_configurado')->nullable();
            $table->timestamp('iniciado_en');
            // Hora máxima permitida para enviar este intento, calculada al
            // iniciarlo (iniciado_en + tiempo_limite_minutos + tolerancia).
            // Nula si el cuestionario no tiene tiempo límite.
            $table->timestamp('fecha_limite')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->timestamp('calificado_en')->nullable();
            $table->unsignedTinyInteger('calificacion')->nullable();
            $table->boolean('aprobado')->nullable();
            $table->timestamps();

            $table->unique(['cuestionario_id', 'user_id', 'numero_intento'], 'intentos_cuestionario_unico');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intentos_cuestionario');
    }
};
