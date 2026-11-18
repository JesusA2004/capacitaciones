<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Quitar de mi lista» en Mis pendientes: cada persona descarta un
 * pendiente PARA SÍ (con motivo opcional) sin cerrarlo para los demás.
 * Si el pendiente se vuelve a generar por un evento nuevo es otra fila de
 * tareas_rh y reaparece. Ver docs/PENDIENTES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tarea_rh_descartes')) {
            return;
        }

        Schema::create('tarea_rh_descartes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tarea_rh_id');
            $table->unsignedBigInteger('user_id');
            $table->string('motivo', 255)->nullable();
            $table->timestamps();

            $table->unique(['tarea_rh_id', 'user_id'], 'tarea_rh_descartes_tarea_usuario_unique');
            $table->index('user_id', 'tarea_rh_descartes_usuario_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarea_rh_descartes');
    }
};
