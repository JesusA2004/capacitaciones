<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Asignar inducción» (RH, onboarding.gestionar) — ver
 * App\Services\Onboarding\AsignacionInduccionService:
 *
 *  - onboarding_procesos.tipo: 'ingreso' (el de siempre, nace al firmar
 *    contratos) o 'reinduccion' (RH le vuelve a asignar la Inducción Mr. Lana
 *    a alguien ya activo). Una reinducción solo lleva el módulo institucional
 *    con evaluación formal y al cerrarse NUNCA activa a nadie ni toca su
 *    contratación (OnboardingService::completar()).
 *  - induccion_asignaciones: qué asignó RH (presentación y/o material), con
 *    fecha límite y mensaje opcionales. `vigente` es true para la asignación
 *    activa del avance y NULL para las retiradas/reemplazadas (historial),
 *    mismo patrón que induccion_materiales_explicacion.activo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_procesos', function (Blueprint $table): void {
            $table->string('tipo', 20)->default('ingreso')->after('reingreso_id');
            $table->index(['colaborador_id', 'tipo'], 'onboarding_procesos_colaborador_tipo_idx');
        });

        Schema::create('induccion_asignaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('onboarding_proceso_id')->constrained('onboarding_procesos')->cascadeOnDelete();
            $table->foreignId('onboarding_avance_id')->constrained('onboarding_avances')->cascadeOnDelete();
            $table->boolean('incluye_presentacion')->default(true);
            $table->boolean('incluye_material')->default(true);
            $table->date('fecha_limite')->nullable();
            $table->text('mensaje')->nullable();
            $table->boolean('vigente')->nullable();
            $table->foreignId('asignada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('asignada_en');
            $table->foreignId('retirada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retirada_en')->nullable();
            $table->string('motivo_retiro', 500)->nullable();
            $table->timestamps();

            $table->unique(['onboarding_avance_id', 'vigente'], 'induccion_asignaciones_avance_vigente_unique');
            $table->index(['colaborador_id', 'vigente'], 'induccion_asignaciones_colaborador_vigente_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('induccion_asignaciones');

        Schema::table('onboarding_procesos', function (Blueprint $table): void {
            $table->dropIndex('onboarding_procesos_colaborador_tipo_idx');
            $table->dropColumn('tipo');
        });
    }
};
