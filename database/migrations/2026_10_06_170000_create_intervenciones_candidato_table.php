<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo de excepción cuando RH rechaza y el gerente que entrevistó pide
 * revisión (CLAUDE.md §10-12): nunca otra fase del kanban de candidatos.
 * Ruta resuelta por el grupo del puesto objetivo al solicitarse (snapshot):
 * Gestor/Volante → Regional, cualquier puesto superior → Dirección
 * Comercial. Todo queda inmutable una vez decidido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervenciones_candidato', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('ruta');

            // Snapshot del rechazo de RH que originó la intervención.
            $table->foreignId('rechazo_rh_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rechazo_rh_motivo')->nullable();
            $table->timestamp('rechazo_rh_en')->nullable();

            // Solicitud del gerente que entrevistó.
            $table->foreignId('gerente_solicitante_id')->constrained('users');
            $table->text('motivo_solicitud');
            $table->timestamp('solicitada_en');

            // Decisión de Regional o Dirección Comercial (null = pendiente).
            $table->string('estado')->default('pendiente');
            $table->foreignId('aprobador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comentario_decision')->nullable();
            $table->timestamp('decidida_en')->nullable();

            $table->timestamps();

            $table->index(['candidato_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervenciones_candidato');
    }
};
