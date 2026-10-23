<?php

namespace App\Services\Contratos;

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoUsuario;
use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Calendario\CalendarioLaboralService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Control automático de vencimientos (lo ejecuta el scheduler vía
 * `contratos:revisar-vencimientos`, nunca depende de que alguien abra una
 * pantalla). N días antes del vencimiento (config contratos.dias_aviso_vencimiento,
 * 15 por defecto), por cada contrato vigente con fecha de fin:
 *
 *  - crea la evaluación de periodo de prueba (una por contrato: índice único),
 *  - abre las tareas "contrato por vencer" y "evaluación pendiente",
 *  - avisa al COLABORADOR evaluado (la contesta él: regla "evaluacion_pendiente") y que ya se debe
 *    renovar el contrato (regla "contrato_por_vencer": RH, gerencia de su
 *    sucursal, su regional y la Gerencia de RH),
 *  - marca aviso_vencimiento_en para no volver a notificar.
 *
 * Idempotente: puede correr varias veces al día o en paralelo sin duplicar
 * evaluaciones, tareas ni notificaciones (bloqueo de fila + marca de aviso).
 */
class VencimientoContratosService
{
    public const PERMISO_RH = 'evaluaciones.autorizar';

    public const ZONA = 'America/Mexico_City';

    public function __construct(
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly CalendarioLaboralService $calendario,
    ) {}

    /**
     * @return array{revisados: int, avisados: int, errores: int}
     */
    public function revisar(): array
    {
        $dias = (int) config('contratos.dias_aviso_vencimiento', 15);
        // Días HÁBILES (FO-RH-EPP-001: "RH, 15 días hábiles antes"), nunca
        // calendario: un contrato que vence en lunes debe avisarse el lunes
        // de tres semanas atrás, no el domingo.
        // "Hoy" es el día en America/Mexico_City (la app corre en UTC): una
        // corrida manual después de las 18:00 no debe adelantar el aviso.
        $limite = $this->calendario->sumarDiasHabiles(now(self::ZONA), $dias)->toDateString();

        // Una baja que no pasó por el cierre laboral (BajaColaboradorService)
        // deja el contrato «vigente»: nunca se le abre evaluación a quien ya
        // no labora. Sin marcar el aviso: si se reactiva, se procesa normal.
        $ids = ContratoLaboral::query()
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->whereNotNull('fecha_fin')
            ->whereNull('aviso_vencimiento_en')
            ->whereDate('fecha_fin', '<=', $limite)
            ->whereHas('colaborador', fn (Builder $q) => $q
                ->whereNull('deleted_at')
                ->where('estatus', '!=', EstadoUsuario::Inactivo->value)
                ->where(fn (Builder $alta) => $alta->whereNull('estado_alta')->orWhere('estado_alta', '!=', EstadoAltaColaborador::Baja->value)))
            ->pluck('id');

        $avisados = 0;
        $errores = 0;

        foreach ($ids as $id) {
            try {
                if ($this->procesar((int) $id)) {
                    $avisados++;
                }
            } catch (Throwable $e) {
                $errores++;
                Log::error('VencimientoContratosService: no se pudo procesar el contrato.', ['contrato_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return ['revisados' => $ids->count(), 'avisados' => $avisados, 'errores' => $errores];
    }

    /**
     * true si en esta ejecución se creó el aviso (false si otra ejecución
     * ya lo había hecho).
     */
    public function procesar(int $contratoId): bool
    {
        $evaluacion = $this->abrirEvaluacion($contratoId, false);

        if ($evaluacion === null) {
            return false;
        }

        $this->avisar($evaluacion);

        return true;
    }

    /**
     * «Asignar evaluación» desde el tablero de Evaluaciones de periodo de
     * prueba: RH abre AHORA (antes de que el scheduler lo haga solo) la
     * evaluación del contrato vigente del colaborador, para que ÉL la
     * conteste (autoevaluación; nunca RH ni su jefe), con exactamente las mismas
     * tareas y avisos que el scheduler (procesar()). Nunca duplica: una
     * evaluación por contrato (índice único).
     *
     * @throws ValidationException si todavía no se puede asignar (el motivo va en el mensaje).
     */
    public function asignarAhora(Colaborador $colaborador, User $actor): EvaluacionPeriodoPrueba
    {
        $contrato = $this->contratoEvaluable($colaborador);
        $yaTiene = $contrato !== null && EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $contrato->id)->exists();
        $motivo = $this->motivoNoAsignable($colaborador, $contrato, $yaTiene);

        if ($motivo !== null || $contrato === null) {
            throw ValidationException::withMessages(['colaborador' => sprintf('%s: %s', $colaborador->nombreCompleto(), $motivo ?? 'no tiene un contrato vigente con fecha de término.')]);
        }

        $evaluacion = $this->abrirEvaluacion($contrato->id, true);

        if ($evaluacion === null) {
            throw ValidationException::withMessages(['colaborador' => sprintf('%s: su evaluación ya estaba asignada.', $colaborador->nombreCompleto())]);
        }

        $this->avisar($evaluacion);
        $this->auditoria->registrar('evaluacion_asignada', $evaluacion, $actor, ['contrato_laboral_id' => $contrato->id, 'anticipada' => true]);

        return $evaluacion;
    }

    /**
     * Asignación en lote desde el tablero: cada colaborador se procesa por
     * separado (uno que todavía no se puede asignar no detiene a los demás)
     * y solo los que $actor alcanza según la Policy.
     *
     * @param  list<int>  $colaboradorIds
     * @return array{asignados: list<string>, omitidos: list<string>}
     */
    public function asignarVarios(array $colaboradorIds, User $actor): array
    {
        $asignados = [];
        $omitidos = [];
        $colaboradores = Colaborador::query()->whereIn('id', $colaboradorIds)->get()->keyBy('id');

        foreach ($colaboradorIds as $id) {
            $colaborador = $colaboradores->get($id);

            if (! $colaborador instanceof Colaborador || ! Gate::forUser($actor)->allows('asignar', [EvaluacionPeriodoPrueba::class, $colaborador])) {
                $omitidos[] = sprintf('Colaborador #%d: no está dentro de tu alcance.', $id);

                continue;
            }

            try {
                $this->asignarAhora($colaborador, $actor);
                $asignados[] = $colaborador->nombreCompleto();
            } catch (ValidationException $e) {
                $omitidos[] = (string) collect($e->errors())->flatten()->first();
            }
        }

        return ['asignados' => $asignados, 'omitidos' => $omitidos];
    }

    /**
     * Contrato al que se le abre la evaluación: el vigente con fecha de
     * término (capacitación inicial / periodo de prueba). Mismo criterio que
     * revisar().
     */
    public function contratoEvaluable(Colaborador $colaborador): ?ContratoLaboral
    {
        return ContratoLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->whereNotNull('fecha_fin')
            ->orderBy('fecha_fin')
            ->first();
    }

    /**
     * Por qué todavía no se puede asignar la evaluación (null = sí se puede).
     * Texto en español para mostrarlo tal cual en el tablero.
     */
    public function motivoNoAsignable(Colaborador $colaborador, ?ContratoLaboral $contrato, bool $yaTieneEvaluacion = false): ?string
    {
        if (! $this->sigueLaborando($colaborador)) {
            return 'ya no labora en la empresa.';
        }

        if ($contrato === null) {
            return 'aún no tiene un contrato vigente con fecha de término en el sistema. Genera su contrato de capacitación inicial (Contratos) y vuelve a intentarlo.';
        }

        if ($yaTieneEvaluacion) {
            return 'su evaluación ya está asignada.';
        }

        return null;
    }

    /**
     * Crea (una sola vez por contrato) la evaluación pendiente que contesta el
     * propio colaborador (autoevaluación), y marca el aviso
     * del contrato. Null si otra ejecución ya lo hizo o el contrato ya no
     * aplica. `$anticipada`: la pidió RH desde el tablero (no exige que el
     * aviso siga sin marcar, pero tampoco reutiliza una evaluación existente).
     */
    private function abrirEvaluacion(int $contratoId, bool $anticipada): ?EvaluacionPeriodoPrueba
    {
        return DB::transaction(function () use ($contratoId, $anticipada): ?EvaluacionPeriodoPrueba {
            $contrato = ContratoLaboral::query()->lockForUpdate()->find($contratoId);

            if ($contrato === null || $contrato->estado !== EstadoContratoLaboral::Vigente) {
                return null;
            }

            if (! $anticipada && $contrato->aviso_vencimiento_en !== null) {
                return null;
            }

            $contrato->loadMissing('colaborador');
            $colaborador = $contrato->colaborador;

            if (! $this->sigueLaborando($colaborador)) {
                return null;
            }

            $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrCreate(
                ['contrato_laboral_id' => $contrato->id],
                [
                    'colaborador_id' => $colaborador->id,
                    // Autoevaluación: la persona evaluada es quien la contesta.
                    'evaluador_colaborador_id' => $colaborador->id,
                    'estado' => EstadoEvaluacionPrueba::Pendiente,
                    'fecha_limite' => $contrato->fecha_fin?->toDateString(),
                    // Versión vigente del formulario configurable (null = formato de config/).
                    'formulario_evaluacion_id' => app(FormularioEvaluacionService::class)->publicado(ClaveFormularioEvaluacion::PeriodoPrueba)?->id,
                ],
            );

            if ($anticipada && ! $evaluacion->wasRecentlyCreated) {
                return null;
            }

            $contrato->update(['aviso_vencimiento_en' => now()]);

            return $evaluacion;
        });
    }

    /**
     * Misma regla que la consulta de revisar(), repetida tras el bloqueo por
     * si la baja ocurrió entre la consulta y el procesamiento. Un colaborador
     * en incorporación (activado_en null) SÍ sigue: su contrato corre igual.
     */
    private function sigueLaborando(Colaborador $colaborador): bool
    {
        return ! $colaborador->trashed()
            && $colaborador->estatus !== EstadoUsuario::Inactivo
            && $colaborador->estado_alta !== EstadoAltaColaborador::Baja;
    }

    private function avisar(EvaluacionPeriodoPrueba $evaluacion): void
    {
        $evaluacion->loadMissing(['contrato', 'colaborador.user']);
        $contrato = $evaluacion->contrato;
        $colaborador = $evaluacion->colaborador;
        $vence = $contrato->fecha_fin?->format('d/m/Y') ?? '';
        $nombre = $colaborador->nombreCompleto();
        // Autoevaluación: la contesta el propio colaborador (nunca RH ni su jefe).
        $colaboradorUsuario = $colaborador->user;

        $this->tareas->abrir(TipoTarea::ContratoPorVencer, $contrato, [
            'titulo' => "Contrato por vencer: {$nombre} ({$vence})",
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $colaborador,
            'permiso' => self::PERMISO_RH,
            'accion' => 'revisar_vencimiento',
            'vence_en' => $contrato->fecha_fin,
        ]);

        app(EvaluacionPeriodoPruebaService::class)->asignarAlColaborador(
            $evaluacion,
            'Contesta tu evaluación de periodo de prueba',
            "Tu contrato de capacitación inicial vence el {$vence}. Contesta tu evaluación en Mi espacio.",
            $contrato->fecha_fin,
        );

        // El colaborador evaluado (o, si la regla se configuró distinto, quien indique).
        $avisados = $this->notificador->notificarEvento(
            'evaluacion_pendiente',
            $colaborador,
            ['evaluador' => $colaboradorUsuario],
            'Contesta tu evaluación de periodo de prueba',
            "Recursos Humanos te asignó tu evaluación de capacitación inicial (periodo de prueba). Contéstala antes del {$vence} desde Mi espacio.",
            $evaluacion,
            EvaluacionPeriodoPruebaService::ACCION_CONTESTAR,
            'alta',
        );

        $colaborador->loadMissing('puesto');
        $duracion = '';

        if ($contrato->fecha_fin !== null) {
            $meses = max(1, (int) round($contrato->fecha_inicio->diffInMonths($contrato->fecha_fin->copy()->addDay())));
            $duracion = sprintf(' (contrato de %d %s%s)', $meses, $meses === 1 ? 'mes' : 'meses', $colaborador->puesto !== null ? ' como '.$colaborador->puesto->nombre : '');
        }

        // "Ya se debe renovar el contrato": por defecto RH con alcance y
        // SIEMPRE la gerencia de su sucursal, su regional y la Gerencia de
        // RH (regla "contrato_por_vencer"), sin repetir a quien ya recibió
        // el aviso de evaluar.
        $this->notificador->notificarEvento(
            'contrato_por_vencer',
            $colaborador,
            ['excluir' => array_values($avisados->map(fn ($usuario) => $usuario->id)->all())],
            'Ya se debe renovar el contrato',
            "El contrato de {$nombre}{$duracion} vence el {$vence}. Recuerden evaluar el periodo de prueba y decidir la renovación.",
            $contrato,
            'revisar_vencimiento',
            'alta',
        );

        $this->auditoria->registrar('contrato_aviso_vencimiento', $contrato, null, ['evaluacion_id' => $evaluacion->id, 'fecha_fin' => $contrato->fecha_fin?->toDateString()]);
    }
}
