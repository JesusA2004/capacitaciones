<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda de entrevistas y estudios socioeconómicos: citas PROGRAMADAS. Los
 * registros realizados siguen en candidato_entrevistas y
 * candidato_socioeconomicos; al capturar el resultado se delega en
 * CandidatoWorkflowService. Solo crea tablas nuevas: no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->string('tipo', 30);
            // Hora de reloj de México, tal como se capturó (la app corre en UTC).
            $table->dateTime('fecha_hora');
            $table->string('estado', 30)->default('programado');
            $table->string('origen', 30);
            $table->foreignId('agendado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsable_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notas')->nullable();
            // Domicilio de la visita: dato personal, solo se entrega con permiso.
            $table->string('domicilio', 300)->nullable();
            $table->string('resultado', 30)->nullable();
            $table->string('motivo_no_viable', 30)->nullable();
            $table->text('comentario_resultado')->nullable();
            $table->foreignId('resultado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resultado_en')->nullable();
            $table->foreignId('reprogramado_de_id')->nullable()->constrained('agenda_eventos')->nullOnDelete();
            $table->foreignId('reprogramado_a_id')->nullable()->constrained('agenda_eventos')->nullOnDelete();
            $table->text('motivo_cancelacion')->nullable();
            // Mismo envío repetido (doble clic, reintento de la app) = el mismo evento.
            $table->string('clave_idempotencia', 64)->nullable()->unique();
            // «candidato:tipo» solo mientras está programado: la base impide dos
            // citas vivas del mismo tipo para un candidato, también en concurrencia.
            $table->string('clave_activa', 60)->nullable()->unique();
            $table->timestamps();

            $table->index(['sucursal_id', 'fecha_hora'], 'agenda_eventos_sucursal_fecha_idx');
            $table->index(['estado', 'fecha_hora'], 'agenda_eventos_estado_fecha_idx');
        });

        Schema::create('agenda_evento_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agenda_evento_id')->constrained('agenda_eventos')->cascadeOnDelete();
            $table->string('accion', 40);
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30)->nullable();
            $table->text('detalle')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_evento_historial');
        Schema::dropIfExists('agenda_eventos');
    }
};
