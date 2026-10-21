<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsable de APROBACIÓN explícito, separado del jefe organizacional
 * (colaboradores.jefe_id, que sale del organigrama). RH lo administra en
 * Organización → Responsables de aprobación. Ámbito:
 *
 *  - por PERSONA (colaborador_id): solo esa persona;
 *  - por PUESTO (puesto_id): todas las personas que ocupan ese puesto.
 *
 * Una sola regla vigente por persona y por puesto (índices únicos). El
 * historial (antes/después, quién, cuándo, motivo) vive en activity_log
 * (log «organigrama», eventos responsable_aprobacion_*). Ver
 * App\Services\Solicitudes\AprobacionOrganizacionalService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsables_aprobacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->cascadeOnDelete();
            $table->foreignId('responsable_colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('motivo', 500)->nullable();
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('colaborador_id', 'responsables_aprobacion_colaborador_uq');
            $table->unique('puesto_id', 'responsables_aprobacion_puesto_uq');
            $table->index('responsable_colaborador_id', 'responsables_aprobacion_responsable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsables_aprobacion');
    }
};
