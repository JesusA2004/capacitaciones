<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Destinatarios de una asignacion antes de materializarse: uno o varios
     * usuarios, sucursales, departamentos, puestos, roles, o todos.
     */
    public function up(): void
    {
        Schema::create('asignacion_destinos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_id')->constrained('asignaciones')->cascadeOnDelete();
            $table->string('tipo_destino');
            $table->unsignedBigInteger('destino_id')->nullable();
            $table->timestamps();

            $table->unique(['asignacion_id', 'tipo_destino', 'destino_id'], 'asignacion_destinos_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_destinos');
    }
};
