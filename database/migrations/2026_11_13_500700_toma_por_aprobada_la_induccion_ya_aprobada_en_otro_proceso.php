<?php

use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\TipoProcesoOnboarding;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingProceso;
use App\Services\Onboarding\EvaluacionInduccionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Producción (2026-11-06): quien aprobó la inducción en un proceso (p. ej.
 * una reinducción asignada antes de firmar contratos) y DESPUÉS se le abrió
 * su onboarding de ingreso aparecía en «Inducción institucional» como
 * «Viendo · 0 %» (y con una asignación nueva) mientras el tablero de
 * candidatos ya decía «Inducción aprobada».
 *
 * Para cada onboarding de ingreso abierto, sin evaluación propia, con el
 * módulo de inducción sin aprobar y cuyo colaborador ya tiene una
 * evaluación APROBADA, EvaluacionInduccionService::habilitarPara() aprueba
 * su módulo de inducción (mismo camino que la app en vivo). Aditiva e
 * idempotente: no borra asignaciones, avances ni evaluaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $aprobados = EvaluacionInduccion::query()
            ->where('estado', EstadoEvaluacionInduccion::Aprobada->value)
            ->pluck('colaborador_id')
            ->unique()
            ->all();

        if ($aprobados === []) {
            return;
        }

        $procesos = OnboardingProceso::query()
            ->whereIn('colaborador_id', $aprobados)
            ->whereNotIn('estado', [EstadoOnboarding::Completado->value, EstadoOnboarding::Cancelado->value])
            ->where(fn (Builder $q) => $q->whereNull('tipo')->orWhere('tipo', '!=', TipoProcesoOnboarding::Reinduccion->value))
            ->whereNotIn('id', EvaluacionInduccion::query()->select('onboarding_proceso_id'))
            ->whereHas('avances', fn (Builder $q) => $q
                ->where('estado', '!=', EstadoAvanceOnboarding::Aprobado->value)
                ->whereHas('modulo', fn (Builder $m) => $m->whereNotNull('evaluacion_formal_clave')))
            ->get();

        $servicio = app(EvaluacionInduccionService::class);

        foreach ($procesos as $proceso) {
            $servicio->habilitarPara($proceso);
            Log::info('Inducción ya aprobada en otro proceso: módulo aprobado en el ingreso.', ['onboarding_proceso_id' => $proceso->id, 'colaborador_id' => $proceso->colaborador_id]);
        }
    }

    /** Sin reversa: volver a dejarlos sin aprobar los detendría otra vez. */
    public function down(): void {}
};
