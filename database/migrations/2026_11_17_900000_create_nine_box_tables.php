<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nine Box (Seguimiento, docs/NINE_BOX.md):
 *
 * - nine_box_configuraciones: pesos, metas y cortes VERSIONADOS. Cada
 *   cambio inserta una versión nueva (quién, cuándo y por qué); la vigente
 *   es la más reciente. Nunca se edita ni se borra una versión.
 * - nine_box_calibraciones: el potencial que captura el jefe directo o RH
 *   por colaborador y periodo (bajo/medio/alto + comentario). Historial
 *   completo: recalibrar inserta otra fila; nunca se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nine_box_configuraciones', function (Blueprint $table) {
            $table->id();
            $table->json('valores');
            $table->string('motivo', 255)->nullable();
            $table->foreignId('guardada_por')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('nine_box_calibraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->string('periodo_tipo', 20);
            // «M-2026-06» (mes de Reportes) o «2026-T2» (trimestre).
            $table->string('periodo_clave', 30);
            $table->string('periodo_etiqueta', 60);
            // Último día del periodo: una calibración sigue vigente en los
            // periodos siguientes hasta que alguien recalibra.
            $table->date('periodo_fin');
            $table->string('nivel', 10);
            $table->text('comentario');
            $table->foreignId('capturada_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['colaborador_id', 'periodo_fin'], 'nine_box_calibraciones_colaborador_fin_idx');
            $table->index(['colaborador_id', 'periodo_tipo', 'periodo_clave'], 'nine_box_calibraciones_colaborador_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nine_box_calibraciones');
        Schema::dropIfExists('nine_box_configuraciones');
    }
};
