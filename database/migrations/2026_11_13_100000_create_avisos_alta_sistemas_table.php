<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo «Alta para asignar usuarios» a Sistemas (config/notificaciones.php).
 * Un registro por CONTRATACIÓN (origen = candidato que entró a contratación,
 * o reingreso autorizado): es la marca persistente que hace idempotente el
 * envío — un reintento de la cola nunca lo duplica, y un reingreso (otra
 * contratación de la misma persona) sí genera un aviso nuevo. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avisos_alta_sistemas')) {
            return;
        }

        Schema::create('avisos_alta_sistemas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('origen_tipo', 20);
            $table->unsignedBigInteger('origen_id');
            $table->string('estado', 20)->default('pendiente');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->timestamp('enviado_en')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->text('destinatarios')->nullable();
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['origen_tipo', 'origen_id'], 'avisos_alta_sistemas_origen_unique');
            $table->index(['colaborador_id', 'estado'], 'avisos_alta_sistemas_colaborador_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_alta_sistemas');
    }
};
