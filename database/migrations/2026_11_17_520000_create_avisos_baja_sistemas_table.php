<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correos de BAJA a Sistemas (App\Services\Notificaciones\AvisoBajaSistemasService):
 * «dar de baja accesos» cuando se aplica la baja y «baja concluida» cuando
 * RH registra la entrega del finiquito. Un registro por baja y por evento
 * (origen_tipo + origen_id + evento únicos): la marca persistente que hace
 * idempotente el envío. También es el pendiente de la bandeja «Pendientes
 * de baja en sistemas» (atendido_en). Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avisos_baja_sistemas')) {
            return;
        }

        Schema::create('avisos_baja_sistemas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('origen_tipo', 20);
            $table->unsignedBigInteger('origen_id');
            $table->string('evento', 30);
            $table->string('estado', 20)->default('pendiente');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->timestamp('enviado_en')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->text('destinatarios')->nullable();
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('atendido_en')->nullable();
            $table->foreignId('atendido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notas_atencion')->nullable();
            $table->timestamps();

            $table->unique(['origen_tipo', 'origen_id', 'evento'], 'avisos_baja_sistemas_origen_evento_unique');
            $table->index(['colaborador_id', 'estado'], 'avisos_baja_sistemas_colaborador_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_baja_sistemas');
    }
};
