<?php

namespace App\Services\EvaluacionesTecnicas;

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoEvaluacionTecnica;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\EvaluacionTecnica;
use App\Models\PasoEvaluacionTecnica;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Programador de evaluaciones técnicas (comando diario
 * `evaluaciones-tecnicas:programar`). Idempotente:
 *
 *  1. Por cada paso activo del calendario, genera la evaluación a cada
 *     colaborador ACTIVO del puesto cuyo día N desde su fecha de ingreso ya
 *     llegó (dentro de la ventana de gracia, config/evaluaciones_tecnicas.php),
 *     con la versión publicada vigente del formulario, y avisa a quien la
 *     contesta. El índice único (colaborador, puesto, día) impide duplicados.
 *  2. Cancela (sin borrar) las pendientes de quien ya no está activo o cambió
 *     de puesto.
 *  3. Avisa una sola vez cada pendiente que venció.
 */
class ProgramacionEvaluacionTecnicaService
{
    public function __construct(
        private readonly FormularioEvaluacionService $formularios,
        private readonly AvisosEvaluacionTecnicaService $avisos,
    ) {}

    /**
     * @return array{generadas: int, sin_formulario: int, canceladas: int, vencidas: int}
     */
    public function programar(?CarbonInterface $hoy = null): array
    {
        $hoy = CarbonImmutable::parse($hoy ?? FechaMexico::hoy())->startOfDay();
        $gracia = max(0, (int) config('evaluaciones_tecnicas.dias_gracia_generacion', 15));
        $resultado = ['generadas' => 0, 'sin_formulario' => 0, 'canceladas' => 0, 'vencidas' => 0];

        $pasos = PasoEvaluacionTecnica::query()
            ->where('activo', true)
            ->whereHas('plantilla', fn ($q) => $q->where('activa', true))
            ->with('plantilla')
            ->get();

        foreach ($pasos as $paso) {
            $plantilla = $paso->plantilla;
            $formulario = $plantilla !== null ? $this->formularios->publicado(ClaveFormularioEvaluacion::Tecnica, $plantilla) : null;

            $candidatos = Colaborador::query()
                ->where('puesto_id', $paso->puesto_id)
                ->where('estatus', EstadoUsuario::Activo->value)
                ->whereNotNull('fecha_ingreso')
                ->whereDate('fecha_ingreso', '<=', $hoy->subDays($paso->dias)->toDateString())
                ->whereDate('fecha_ingreso', '>=', $hoy->subDays($paso->dias + $gracia)->toDateString())
                ->whereDoesntHave('evaluacionesTecnicas', fn ($q) => $q->where('puesto_id', $paso->puesto_id)->where('dias', $paso->dias))
                ->get();

            if ($candidatos->isEmpty()) {
                continue;
            }

            if ($formulario === null) {
                $resultado['sin_formulario'] += $candidatos->count();
                Log::warning('Evaluaciones técnicas: el formulario del paso no tiene versión publicada; no se generó.', [
                    'paso' => $paso->id, 'plantilla' => $paso->plantilla_evaluacion_tecnica_id, 'colaboradores' => $candidatos->count(),
                ]);

                continue;
            }

            foreach ($candidatos as $colaborador) {
                $evaluacion = $this->generar($colaborador, $paso, $formulario->id);

                if ($evaluacion !== null) {
                    $resultado['generadas']++;
                    $this->avisos->pendiente($evaluacion);
                }
            }
        }

        $resultado['canceladas'] = $this->cancelarHuerfanas();
        $resultado['vencidas'] = $this->avisarVencidas($hoy);

        return $resultado;
    }

    private function generar(Colaborador $colaborador, PasoEvaluacionTecnica $paso, int $formularioId): ?EvaluacionTecnica
    {
        /** @var CarbonInterface $ingreso */
        $ingreso = $colaborador->fecha_ingreso;
        $programada = CarbonImmutable::parse($ingreso)->startOfDay()->addDays($paso->dias);

        try {
            return DB::transaction(fn (): EvaluacionTecnica => EvaluacionTecnica::query()->create([
                'colaborador_id' => $colaborador->id,
                'puesto_id' => $paso->puesto_id,
                'paso_evaluacion_tecnica_id' => $paso->id,
                'plantilla_evaluacion_tecnica_id' => $paso->plantilla_evaluacion_tecnica_id,
                'formulario_evaluacion_id' => $formularioId,
                'nombre' => $paso->nombre,
                'dias' => $paso->dias,
                'es_final' => $paso->es_final,
                'fecha_programada' => $programada->toDateString(),
                'fecha_limite' => $programada->addDays($paso->dias_para_contestar)->toDateString(),
                'contesta' => $paso->contesta,
                // Jefe directo del organigrama (nunca a mano); sin jefe, su gerente.
                'evaluador_colaborador_id' => $colaborador->jefe_id ?? $colaborador->gerente_id,
                'calificacion_minima' => $paso->calificacion_minima,
                'estado' => EstadoEvaluacionTecnica::Pendiente,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Otra corrida la generó al mismo tiempo: nada que hacer.
            return null;
        }
    }

    private function cancelarHuerfanas(): int
    {
        $huerfanas = EvaluacionTecnica::query()
            ->where('estado', EstadoEvaluacionTecnica::Pendiente->value)
            ->where(fn ($q) => $q
                ->whereDoesntHave('colaborador', fn ($c) => $c->where('estatus', EstadoUsuario::Activo->value))
                ->orWhereHas('colaborador', fn ($c) => $c->whereColumn('colaboradores.puesto_id', '!=', 'evaluaciones_tecnicas.puesto_id')))
            ->with('colaborador:id,estatus,puesto_id')
            ->get();

        foreach ($huerfanas as $evaluacion) {
            $motivo = $evaluacion->colaborador === null || $evaluacion->colaborador->estatus !== EstadoUsuario::Activo
                ? 'El colaborador ya no está activo.'
                : 'El colaborador cambió de puesto.';

            $evaluacion->update([
                'estado' => EstadoEvaluacionTecnica::Cancelada,
                'cancelada_en' => now(),
                'motivo_cancelacion' => $motivo,
            ]);
        }

        return $huerfanas->count();
    }

    private function avisarVencidas(CarbonImmutable $hoy): int
    {
        $vencidas = EvaluacionTecnica::query()
            ->where('estado', EstadoEvaluacionTecnica::Pendiente->value)
            ->whereDate('fecha_limite', '<', $hoy->toDateString())
            ->with(['colaborador.user', 'evaluador.user'])
            ->get();

        foreach ($vencidas as $evaluacion) {
            // Idempotente: la ocurrencia «vencida» se entrega una sola vez.
            $this->avisos->vencida($evaluacion);
        }

        return $vencidas->count();
    }
}
