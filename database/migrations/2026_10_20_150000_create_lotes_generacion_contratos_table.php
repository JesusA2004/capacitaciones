<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corridas de «Generar todos» del módulo Contratos: quién la lanzó,
 * progreso y resumen auditable (generados, omitidos con motivo, errores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_generacion_contratos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 20)->default('en_cola')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('generados')->default(0);
            $table->unsignedInteger('sin_cambios')->default(0);
            $table->unsignedInteger('omitidos')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->json('resultados')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('terminado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes_generacion_contratos');
    }
};
