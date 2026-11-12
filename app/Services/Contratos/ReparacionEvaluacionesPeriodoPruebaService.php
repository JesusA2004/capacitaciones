<?php

namespace App\Services\Contratos;

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\TipoTarea;
use App\Models\Aprobacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Reclutamiento\FaseContratadoService;
use App\Services\Tareas\TareaService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Regularización de evaluaciones de periodo de prueba (FO-RH-EPP-001)
 * asignadas a quien NO cumplía la regla (producción 2026-11-12: se le asignó
 * a personas que seguían en inducción, y el tablero las adelantó de fase).
 *
 * Con la MISMA regla que hoy impide asignarlas
 * (ElegibilidadEvaluacionPeriodoPruebaService) se revisa cada evaluación:
 *
 *  - «induccion»: la persona aún no alcanza «Capacitación inicial»;
 *  - «fecha»: aún no llega su fecha (faltan más de 15 días hábiles para que
 *    termine su periodo de prueba).
 *
 * Solo se RETIRA la que nadie ha contestado (estado «pendiente», sin
 * respuestas, sin aprobaciones ni excepciones): se resuelven sus tareas, se
 * libera el aviso del contrato (para que se vuelva a abrir sola cuando la
 * persona sí cumpla) y queda la bitácora con la fotografía completa. Una
 * evaluación contestada NUNCA se toca: se lista para que una persona decida.
 * No se avisa a nadie.
 *
 * @phpstan-type Caso array{evaluacion_id: int, colaborador_id: int, colaborador: string, numero_empleado: string|null, sucursal: string|null, puesto: string|null, fase: string, estado: string, asignada_en: string|null, fin_periodo: string|null, asignable_desde: string|null, codigo: string, motivo: string, accion: string, detalle_accion: string}
 */
class ReparacionEvaluacionesPeriodoPruebaService
{
    public const ACCION_RETIRAR = 'retirar';

    public const ACCION_REVISAR = 'revisar';

    public const ACCION_OMITIR = 'omitir';

    public function __construct(
        private readonly ElegibilidadEvaluacionPeriodoPruebaService $elegibilidad,
        private readonly FaseContratadoService $fases,
        private readonly TareaService $tareas,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Evaluaciones asignadas a quien HOY todavía no cumple la regla.
     *
     * @param  bool  $incluirFecha  true = también se retiran las asignadas antes de su fecha (por omisión solo se listan).
     * @return list<Caso>
     */
    public function casos(bool $incluirFecha = false, ?CarbonInterface $hoy = null): array
    {
        $evaluaciones = EvaluacionPeriodoPrueba::query()
            ->with(['colaborador.puesto', 'colaborador.sucursalPrincipal:id,nombre', 'contrato', 'solicitudesExcepcion'])
            ->orderBy('id')
            ->get();
        $hechos = $this->fases->hechosParaColaboradores($evaluaciones->pluck('colaborador_id')->all());
        $conAprobaciones = Aprobacion::query()
            ->where('aprobable_type', (new EvaluacionPeriodoPrueba)->getMorphClass())
            ->whereIn('aprobable_id', $evaluaciones->pluck('id'))
            ->pluck('aprobable_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
        $casos = [];

        foreach ($evaluaciones as $evaluacion) {
            $colaborador = $evaluacion->colaborador;
            // Sin contar la propia evaluación: ¿hoy se le podría asignar?
            $elegibilidad = $this->elegibilidad->evaluar($colaborador, $evaluacion->contrato, false, $hoy, $hechos[$colaborador->id] ?? null);

            if (! in_array($elegibilidad['codigo'], [ElegibilidadEvaluacionPeriodoPruebaService::INDUCCION, ElegibilidadEvaluacionPeriodoPruebaService::FECHA], true)) {
                continue;
            }

            $sinContestar = $evaluacion->estado === EstadoEvaluacionPrueba::Pendiente
                && $evaluacion->capturada_en === null
                && ($evaluacion->criterios ?? []) === []
                && $evaluacion->solicitudesExcepcion->isEmpty()
                && ! $conAprobaciones->has($evaluacion->id);
            $soloFecha = $elegibilidad['codigo'] === ElegibilidadEvaluacionPeriodoPruebaService::FECHA;

            [$accion, $detalle] = match (true) {
                ! $sinContestar => [self::ACCION_REVISAR, sprintf('Ya tiene respuestas o decisión («%s»): no se toca, revísala a mano.', $evaluacion->estado->etiqueta())],
                $soloFecha && ! $incluirFecha => [self::ACCION_OMITIR, 'Asignada antes de su fecha, pero ya aprobó su inducción: se deja (usa --incluir-fecha para retirarla).'],
                default => [self::ACCION_RETIRAR, 'Sin contestar: se retira y se abrirá sola cuando cumpla.'],
            };

            $casos[] = [
                'evaluacion_id' => $evaluacion->id,
                'colaborador_id' => $colaborador->id,
                'colaborador' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
                'fase' => $elegibilidad['fase_etiqueta'],
                'estado' => $evaluacion->estado->etiqueta(),
                'asignada_en' => $evaluacion->created_at?->toDateString(),
                'fin_periodo' => $elegibilidad['fin_periodo'],
                'asignable_desde' => $elegibilidad['asignable_desde'],
                'codigo' => (string) $elegibilidad['codigo'],
                'motivo' => (string) $elegibilidad['motivo_corto'],
                'accion' => $accion,
                'detalle_accion' => $detalle,
            ];
        }

        return $casos;
    }

    /**
     * Retira UNA evaluación sin contestar (vuelve a validar que siga sin
     * contestar). No notifica a nadie.
     *
     * @return bool false si ya no procede (alguien la contestó mientras tanto o ya no existe).
     */
    public function retirar(int $evaluacionId, string $codigo): bool
    {
        return DB::transaction(function () use ($evaluacionId, $codigo): bool {
            $evaluacion = EvaluacionPeriodoPrueba::query()->lockForUpdate()->where('id', $evaluacionId)->first();

            if ($evaluacion === null || $evaluacion->estado !== EstadoEvaluacionPrueba::Pendiente || $evaluacion->capturada_en !== null) {
                return false;
            }

            $contrato = ContratoLaboral::query()->where('id', $evaluacion->contrato_laboral_id)->first();
            $colaborador = Colaborador::withTrashed()->where('id', $evaluacion->colaborador_id)->first();

            // Nadie se queda con un pendiente que ya no abre nada.
            $this->tareas->resolverTodas($evaluacion);

            if ($contrato !== null) {
                $this->tareas->resolver(TipoTarea::ContratoPorVencer, $contrato);
                // Sin la marca, el scheduler la vuelve a abrir cuando cumpla.
                $contrato->forceFill(['aviso_vencimiento_en' => null])->saveQuietly();
            }

            $this->auditoria->registrar('evaluacion_periodo_prueba_retirada', $colaborador, null, [
                'evaluacion_id' => $evaluacion->id,
                'colaborador_id' => $evaluacion->colaborador_id,
                'contrato_laboral_id' => $evaluacion->contrato_laboral_id,
                'evaluador_colaborador_id' => $evaluacion->evaluador_colaborador_id,
                'estado' => $evaluacion->estado->value,
                'fecha_limite' => $evaluacion->fecha_limite?->toDateString(),
                'asignada_en' => $evaluacion->created_at?->toIso8601String(),
                'motivo' => $codigo,
                'detalle' => 'Asignada a quien aún no cumplía la regla (inducción aprobada y fecha alcanzada). Retirada por people:reparar-evaluaciones-periodo-prueba.',
            ]);

            $evaluacion->delete();

            return true;
        });
    }
}
