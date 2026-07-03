<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cumplimiento (Fase 9, sección 10): el orden de preguntas se
 * recalculaba con shuffle() en cada carga de página en vez de fijarse una
 * sola vez por intento, y no había ninguna validación de tiempo límite en
 * el backend. Estas columnas se llenan una sola vez al iniciar el intento
 * (IntentoCuestionarioService::iniciarIntento) y ya no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intentos_cuestionario', function (Blueprint $table) {
            // Orden fijo de preguntas: array de IDs de `preguntas` en el
            // orden exacto en que se le mostraron a este intento.
            $table->json('orden_preguntas')->nullable()->after('numero_intento');
            // Orden fijo de opciones por pregunta (solo si el cuestionario
            // tiene aleatorizar_opciones activo): mapa pregunta_id => array
            // ordenado de IDs de `opciones_pregunta`.
            $table->json('orden_opciones')->nullable()->after('orden_preguntas');
            // Puntaje configurado (pivote cuestionario_pregunta.puntos o
            // pregunta.puntos) capturado al iniciar el intento, para que un
            // cambio posterior en la configuración del cuestionario no
            // altere la calificación de intentos ya en curso.
            $table->json('puntaje_configurado')->nullable()->after('orden_opciones');
            // Hora máxima permitida para enviar este intento, calculada al
            // iniciarlo (iniciado_en + tiempo_limite_minutos + tolerancia).
            // Nula si el cuestionario no tiene tiempo límite.
            $table->timestamp('fecha_limite')->nullable()->after('iniciado_en');
        });
    }

    public function down(): void
    {
        Schema::table('intentos_cuestionario', function (Blueprint $table) {
            $table->dropColumn(['orden_preguntas', 'orden_opciones', 'puntaje_configurado', 'fecha_limite']);
        });
    }
};
