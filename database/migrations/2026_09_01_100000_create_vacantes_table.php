<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacantes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('gerente_solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsable_rh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo', 30);
            $table->string('estado', 30)->default('abierta');
            // Obligatorio al cancelar manualmente: por que una ruta/plaza ya
            // no se va a cubrir (ver VacanteController::actualizarEstado).
            $table->text('motivo_cancelacion')->nullable();
            $table->date('fecha_apertura');
            $table->date('fecha_estimada_cobertura')->nullable();
            $table->text('observaciones')->nullable();
            // Marca las vacantes generadas automaticamente por HeadcountService
            // (ver docs/HEADCOUNT_Y_VACANTES.md) para poder sincronizarlas sin
            // tocar las vacantes creadas manualmente por RH.
            $table->boolean('generada_automaticamente')->default(false);
            $table->foreignId('headcount_target_id')->nullable()->constrained('headcount_targets')->nullOnDelete();
            // Cantidad real de plazas detrás de la vacante:
            // plazas_requeridas/plazas_disponibles reflejan en vivo el faltante
            // de HeadcountService::vacantesDerivadas() (se actualizan en cada
            // VacanteAutoGenerationService::sincronizar()); plazas_cubiertas
            // cuenta candidatos "contratado" ligados a esta vacante.
            $table->unsignedSmallInteger('plazas_requeridas')->default(0);
            $table->unsignedSmallInteger('plazas_cubiertas')->default(0);
            $table->unsignedSmallInteger('plazas_disponibles')->default(0);
            // Presupuesto mensual de la plaza, opcional: alimenta los KPIs de
            // costo de contratación en Vacantes/Candidatos.
            $table->decimal('sueldo_mensual', 10, 2)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacantes');
    }
};
