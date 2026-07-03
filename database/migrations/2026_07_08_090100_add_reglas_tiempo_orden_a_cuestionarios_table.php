<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cumplimiento (Fase 9, sección 10): faltaba aleatorizar el
 * orden de las opciones y una tolerancia configurable para el tiempo
 * límite (variaciones normales de red/latencia al enviar el intento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuestionarios', function (Blueprint $table) {
            $table->boolean('aleatorizar_opciones')->default(false)->after('aleatorizar_preguntas');
            $table->unsignedInteger('tolerancia_segundos')->default(30)->after('tiempo_limite_minutos');
        });
    }

    public function down(): void
    {
        Schema::table('cuestionarios', function (Blueprint $table) {
            $table->dropColumn(['aleatorizar_opciones', 'tolerancia_segundos']);
        });
    }
};
