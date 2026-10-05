<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de cambio de foto de perfil (FotoColaboradorService): la
 * primera foto del colaborador queda oficial al instante; a partir de ahí
 * cada foto nueva es una PROPUESTA que RH aprueba o rechaza. Solo guarda
 * rutas del NAS privado, nunca el binario. Incremental: no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_foto_perfil', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('solicitado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('foto_path', 500);
            $table->string('foto_anterior_path', 500)->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->text('motivo_rechazo')->nullable();
            $table->foreignId('revisado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'estado'], 'cambios_foto_colaborador_estado_idx');
            $table->index(['estado', 'created_at'], 'cambios_foto_estado_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_foto_perfil');
    }
};
