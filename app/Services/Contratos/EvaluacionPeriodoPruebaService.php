<?php

namespace App\Services\Contratos;

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\PrioridadTarea;
use App\Enums\ResultadoEvaluacion;
use App\Enums\TipoBaja;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Evaluación del periodo de prueba:
 *
 *   pendiente (scheduler) → capturada (jefe inmediato) → autorizada (RH/Dirección)
 *                                       ↘ devuelta (RH pide corrección) ↗
 *
 * Al autorizar:
 *  - renovar = sí → ContratoLaboralService::renovar(): contrato indeterminado y
 *    su documento entra al flujo firma → impresión → firma física → envío →
 *    recepción → archivo.
 *  - renovar = no → CierreLaboralService::iniciar() con tipo "no renovación".
 */
class EvaluacionPeriodoPruebaService
{
    public const PERMISO_AUTORIZAR = 'evaluaciones.autorizar';

    public function __construct(
        private readonly ContratoLaboralService $contratos,
        private readonly CierreLaboralService $cierres,
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  criterios[{criterio, calificacion, comentario?}], resultado?, recomienda_renovar, observaciones?, fecha_evaluacion? (validado por CapturarEvaluacionRequest).
     */
    public function capturar(EvaluacionPeriodoPrueba $evaluacion, User $evaluador, array $datos): EvaluacionPeriodoPrueba
    {
        $evaluacion = DB::transaction(function () use ($evaluacion, $evaluador, $datos): EvaluacionPeriodoPrueba {
            $evaluacion = EvaluacionPeriodoPrueba::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if (! $evaluacion->estado->permiteCaptura()) {
                throw ValidationException::withMessages(['estado' => "La evaluación ya está «{$evaluacion->estado->etiqueta()}»."]);
            }

            $criterios = [];

            foreach ((array) $datos['criterios'] as $criterio) {
                if (! is_array($criterio)) {
                    continue;
                }

                $criterios[] = [
                    'criterio' => (string) $criterio['criterio'],
                    'calificacion' => round((float) $criterio['calificacion'], 2),
                    'comentario' => isset($criterio['comentario']) ? (string) $criterio['comentario'] : null,
                ];
            }

            $promedio = count($criterios) > 0 ? round(array_sum(array_column($criterios, 'calificacion')) / count($criterios), 2) : 0.0;
            $resultado = isset($datos['resultado'])
                ? ResultadoEvaluacion::from((string) $datos['resultado'])
                : ($promedio >= (float) config('contratos.calificacion_minima_aprobatoria', 7) ? ResultadoEvaluacion::Aprobado : ResultadoEvaluacion::NoAprobado);

            $evaluacion->update([
                'criterios' => $criterios,
                'calificacion' => $promedio,
                'resultado' => $resultado,
                'recomienda_renovar' => (bool) $datos['recomienda_renovar'],
                'observaciones' => $datos['observaciones'] ?? null,
                'fecha_evaluacion' => $datos['fecha_evaluacion'] ?? now()->toDateString(),
                'capturada_por' => $evaluador->id,
                'capturada_en' => now(),
                'estado' => EstadoEvaluacionPrueba::Capturada,
            ]);

            return $evaluacion;
        });

        $evaluacion->loadMissing('colaborador');
        $this->tareas->resolver(TipoTarea::EvaluacionPendiente, $evaluacion, $evaluador);
        $this->tareas->abrir(TipoTarea::EvaluacionPorAutorizar, $evaluacion, [
            'titulo' => "Autorizar evaluación: {$evaluacion->colaborador->nombreCompleto()}",
            'descripcion' => $evaluacion->recomienda_renovar ? 'El jefe recomienda renovar.' : 'El jefe recomienda NO renovar.',
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $evaluacion->colaborador,
            'permiso' => self::PERMISO_AUTORIZAR,
            'accion' => 'autorizar_evaluacion',
        ]);

        $this->notificador->notificar(
            $this->notificador->responsablesDe($evaluacion->colaborador, self::PERMISO_AUTORIZAR),
            'evaluacion_capturada',
            'Evaluación de periodo de prueba por autorizar',
            "Se capturó la evaluación de {$evaluacion->colaborador->nombreCompleto()}.",
            $evaluacion,
            'autorizar_evaluacion',
        );

        $this->auditoria->registrar('evaluacion_capturada', $evaluacion, $evaluador, [
            'calificacion' => $evaluacion->calificacion,
            'resultado' => $evaluacion->resultado?->value,
            'recomienda_renovar' => $evaluacion->recomienda_renovar,
        ]);

        return $evaluacion->refresh();
    }

    public function devolver(EvaluacionPeriodoPrueba $evaluacion, User $actor, string $motivo): EvaluacionPeriodoPrueba
    {
        $evaluacion = DB::transaction(function () use ($evaluacion, $motivo): EvaluacionPeriodoPrueba {
            $evaluacion = EvaluacionPeriodoPrueba::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if ($evaluacion->estado !== EstadoEvaluacionPrueba::Capturada) {
                throw ValidationException::withMessages(['estado' => 'Solo una evaluación capturada puede devolverse.']);
            }

            $evaluacion->update(['estado' => EstadoEvaluacionPrueba::Devuelta, 'comentario_autorizacion' => $motivo]);

            return $evaluacion;
        });

        $evaluacion->loadMissing(['colaborador', 'evaluador.user']);
        $this->tareas->resolver(TipoTarea::EvaluacionPorAutorizar, $evaluacion, $actor);
        $evaluadorUsuario = $evaluacion->evaluador?->user;

        $this->tareas->abrir(TipoTarea::EvaluacionPendiente, $evaluacion, [
            'titulo' => "Corregir evaluación: {$evaluacion->colaborador->nombreCompleto()}",
            'descripcion' => $motivo,
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $evaluacion->colaborador,
            'usuario' => $evaluadorUsuario,
            'permiso' => $evaluadorUsuario === null ? self::PERMISO_AUTORIZAR : null,
            'accion' => 'capturar_evaluacion',
        ]);

        if ($evaluadorUsuario !== null) {
            $this->notificador->notificar([$evaluadorUsuario], 'evaluacion_devuelta', 'Evaluación devuelta', $motivo, $evaluacion, 'capturar_evaluacion');
        }

        $this->auditoria->registrar('evaluacion_devuelta', $evaluacion, $actor, ['motivo' => $motivo]);

        return $evaluacion->refresh();
    }

    /**
     * @param  array<string, mixed>  $datos  renovar, comentario?, motivo_no_renovacion?, fecha_efectiva? (validado por AutorizarEvaluacionRequest).
     */
    public function autorizar(EvaluacionPeriodoPrueba $evaluacion, User $actor, array $datos): EvaluacionPeriodoPrueba
    {
        $renovar = (bool) $datos['renovar'];

        $evaluacion = DB::transaction(function () use ($evaluacion, $actor, $datos, $renovar): EvaluacionPeriodoPrueba {
            $evaluacion = EvaluacionPeriodoPrueba::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if ($evaluacion->estado !== EstadoEvaluacionPrueba::Capturada) {
                throw ValidationException::withMessages(['estado' => 'Solo una evaluación capturada puede autorizarse.']);
            }

            $evaluacion->update([
                'estado' => EstadoEvaluacionPrueba::Autorizada,
                'autorizada_por' => $actor->id,
                'autorizada_en' => now(),
                'decision_renovar' => $renovar,
                'comentario_autorizacion' => $datos['comentario'] ?? null,
            ]);

            return $evaluacion;
        });

        $evaluacion->loadMissing(['contrato', 'colaborador']);
        $this->auditoria->registrar('evaluacion_autorizada', $evaluacion, $actor, ['renovar' => $renovar]);
        $this->tareas->resolver(TipoTarea::EvaluacionPorAutorizar, $evaluacion, $actor);
        $this->tareas->resolver(TipoTarea::ContratoPorVencer, $evaluacion->contrato, $actor);

        if ($renovar) {
            $nuevo = $this->contratos->renovar($evaluacion->contrato, $actor);
            $evaluacion->update(['contrato_renovacion_id' => $nuevo->id]);
        } else {
            $contrato = $evaluacion->contrato;
            $fecha = isset($datos['fecha_efectiva']) ? (string) $datos['fecha_efectiva'] : ($contrato->fecha_fin?->toDateString() ?? now()->toDateString());

            $this->cierres->iniciar($evaluacion->colaborador, [
                'tipo_baja' => TipoBaja::NoRenovacion->value,
                'motivo' => isset($datos['motivo_no_renovacion']) ? (string) $datos['motivo_no_renovacion'] : 'No renovación de contrato tras evaluación de periodo de prueba.',
                'fecha_efectiva' => $fecha,
            ], $actor, $evaluacion);
        }

        return $evaluacion->refresh();
    }

    /**
     * Evaluaciones visibles: RH/Dirección por alcance; un jefe solo las de su
     * equipo (donde es evaluador o jefe inmediato).
     *
     * @param  array<string, mixed>  $filtros  estado?, per_page?
     * @return LengthAwarePaginator<int, EvaluacionPeriodoPrueba>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = EvaluacionPeriodoPrueba::query()->with(['colaborador:id,name,apellidos,numero_empleado,jefe_id,sucursal_principal_id', 'contrato.documento', 'contrato.evaluacion']);

        if (! $usuario->can(self::PERMISO_AUTORIZAR)) {
            $query->where(fn (Builder $q) => $q
                ->where('evaluador_colaborador_id', $usuario->colaborador_id ?? 0)
                ->orWhereHas('colaborador', fn (Builder $c) => $c->where('jefe_id', $usuario->colaborador_id ?? 0)));
        } elseif (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('id'));
        }

        $estado = isset($filtros['estado']) ? (string) $filtros['estado'] : null;

        return $query
            ->when($estado, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->orderBy('fecha_limite')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(EvaluacionPeriodoPrueba $evaluacion): array
    {
        return [
            'id' => $evaluacion->id,
            'colaborador' => [
                'id' => $evaluacion->colaborador->id,
                'nombre' => $evaluacion->colaborador->nombreCompleto(),
                'numero_empleado' => $evaluacion->colaborador->numero_empleado,
            ],
            'contrato' => $this->contratos->aArray($evaluacion->contrato),
            'estado' => $evaluacion->estado->value,
            'estado_etiqueta' => $evaluacion->estado->etiqueta(),
            'fecha_limite' => $evaluacion->fecha_limite?->toDateString(),
            'fecha_evaluacion' => $evaluacion->fecha_evaluacion?->toDateString(),
            'criterios' => $evaluacion->criterios ?? [],
            'criterios_sugeridos' => config('contratos.criterios_evaluacion', []),
            'calificacion' => $evaluacion->calificacion,
            'resultado' => $evaluacion->resultado?->value,
            'recomienda_renovar' => $evaluacion->recomienda_renovar,
            'observaciones' => $evaluacion->observaciones,
            'decision_renovar' => $evaluacion->decision_renovar,
            'comentario_autorizacion' => $evaluacion->comentario_autorizacion,
            'autorizada_en' => $evaluacion->autorizada_en?->toIso8601String(),
            'contrato_renovacion_id' => $evaluacion->contrato_renovacion_id,
        ];
    }
}
