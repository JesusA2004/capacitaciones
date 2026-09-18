<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gasto de campañas de reclutamiento por canal (Meta Ads, Indeed,
 * Computrabajo, LinkedIn, referidos, otros) — ver App\Enums\CanalReclutamiento
 * y App\Services\Reclutamiento\CampanaReclutamientoService. Una campaña puede
 * amarrarse a empresa/sucursal/departamento/puesto (gasto dirigido a una
 * posición) o quedarse general (los cuatro nulos): el costo por
 * contratación se calcula distinto en cada caso, nunca se inventa una
 * atribución por puesto para el gasto general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campanas_reclutamiento', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('canal', 30);
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->decimal('monto', 10, 2);
            // Si se deja null, el resumen cuenta candidatos con
            // Candidato::fuente = canal dentro del periodo (ver
            // CampanaReclutamientoService::resumenPeriodo()).
            $table->unsignedInteger('candidatos_generados')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanas_reclutamiento');
    }
};
