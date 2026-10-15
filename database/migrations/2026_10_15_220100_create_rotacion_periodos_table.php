<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un mes de rotación de una sucursal, tal como lo reportó el Excel de RH
 * (bloques ALTAS / BAJAS / PLANTILLA / ROTACIÓN por sucursal). Una sola
 * fila por sucursal + año + mes: volver a subir el mes la reemplaza y el
 * cambio queda en la carga que lo aplicó y en la bitácora de auditoría.
 *
 * índice = bajas × 100 / plantilla final del mes (fórmula del Excel real).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rotacion_periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->string('sucursal_excel')->comment('Nombre de la sucursal tal como venía en el Excel');
            $table->string('hoja_excel')->nullable();
            $table->integer('plantilla_inicial');
            $table->unsignedInteger('altas');
            $table->unsignedInteger('bajas');
            $table->unsignedInteger('bajas_voluntarias')->nullable();
            $table->unsignedInteger('bajas_involuntarias')->nullable();
            $table->integer('plantilla_final');
            $table->decimal('indice', 8, 2)->nullable();
            $table->decimal('indice_excel', 8, 2)->nullable();
            $table->foreignId('carga_id')->constrained('rotacion_cargas');
            $table->timestamps();

            $table->unique(['sucursal_id', 'anio', 'mes'], 'rotacion_periodos_sucursal_anio_mes_unique');
            $table->index(['anio', 'mes'], 'rotacion_periodos_anio_mes_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotacion_periodos');
    }
};
