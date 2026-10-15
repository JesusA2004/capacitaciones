<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada Excel de rotación de personal que RH sube (formato de
 * docs/rotacion/INDICE DE ROTACIÓN DE PERSONAL 2026 actualizado.xlsx). El
 * archivo original queda en el disco `nas` bajo `rotacion/`; `detalle`
 * guarda lo que el análisis encontró (periodos + hallazgos) y es lo que
 * se aplica — nunca se reinterpreta el archivo dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rotacion_cargas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_archivo');
            $table->string('archivo_ruta');
            $table->string('archivo_hash', 64)->index();
            $table->string('estado', 20)->default('analizada')->index();
            $table->foreignId('subido_por')->constrained('users');
            $table->foreignId('aplicado_por')->nullable()->constrained('users');
            $table->timestamp('aplicado_en')->nullable();
            $table->unsignedInteger('periodos_detectados')->default(0);
            $table->unsignedInteger('periodos_nuevos')->default(0);
            $table->unsignedInteger('periodos_reemplazados')->default(0);
            $table->unsignedInteger('periodos_sin_cambios')->default(0);
            $table->unsignedInteger('hallazgos')->default(0);
            $table->json('resumen')->nullable();
            $table->json('detalle')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotacion_cargas');
    }
};
