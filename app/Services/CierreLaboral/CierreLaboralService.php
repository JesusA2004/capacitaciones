<?php

namespace App\Services\CierreLaboral;

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\PrioridadTarea;
use App\Enums\TipoBaja;
use App\Enums\TipoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Tareas\TareaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierre laboral real (no renovación, renuncia voluntaria u otros motivos
 * de App\Enums\TipoBaja):
 *
 *   iniciar → aviso de término / renuncia → cálculo/captura del finiquito →
 *   documento (motor documental) → firmas → confirmación de pago → baja →
 *   expediente cerrado.
 *
 * Reutiliza lo que ya existía en vez de duplicarlo: la solicitud interna de
 * baja (SolicitudesService, tablero de RH), el finiquito (FiniquitoService)
 * y la baja real (BajaColaboradorService, disparada al aprobar la
 * solicitud). NUNCA elimina al colaborador: bloquea acceso, conserva
 * historial y marca el expediente como cerrado.
 */
class CierreLaboralService
{
    public const PERMISO_GESTIONAR = 'cierres.gestionar';

    public function __construct(
        private readonly SolicitudesService $solicitudes,
        private readonly FiniquitoService $finiquitos,
        private readonly MotorDocumentalService $motor,
        private readonly TareaService $tareas,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  tipo_baja, motivo, fecha_efectiva, observaciones? (validado por IniciarCierreRequest).
     */
    public function iniciar(Colaborador $colaborador, array $datos, User $actor, ?EvaluacionPeriodoPrueba $evaluacion = null): CierreLaboral
    {
        $abierto = CierreLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNotIn('estado', [EstadoCierreLaboral::ExpedienteCerrado->value, EstadoCierreLaboral::Cancelado->value])
            ->exists();

        if ($abierto) {
            throw ValidationException::withMessages(['colaborador' => 'El colaborador ya tiene un cierre laboral en proceso.']);
        }

        $tipoBaja = TipoBaja::from((string) $datos['tipo_baja']);
        $motivo = (string) $datos['motivo'];
        $fechaEfectiva = (string) $datos['fecha_efectiva'];
        $observaciones = isset($datos['observaciones']) ? (string) $datos['observaciones'] : null;

        $cierre = DB::transaction(function () use ($colaborador, $actor, $evaluacion, $tipoBaja, $motivo, $fechaEfectiva, $observaciones): CierreLaboral {
            // La solicitud de baja existente es el "expediente del trámite":
            // folio, historial, tablero de RH, evidencia y finiquito.
            $solicitud = $this->solicitudes->crear($actor, [
                'tipo' => TipoSolicitudInterna::BajaColaborador->value,
                'colaborador_objetivo_id' => $colaborador->id,
                'tipo_baja' => $tipoBaja->value,
                'fecha_efectiva' => $fechaEfectiva,
                'motivo' => $motivo,
                'observaciones' => $observaciones,
            ]);

            $this->solicitudes->marcarEnRevision($solicitud, $actor, 'Cierre laboral iniciado.');

            return CierreLaboral::query()->create([
                'colaborador_id' => $colaborador->id,
                'solicitud_interna_id' => $solicitud->id,
                'evaluacion_id' => $evaluacion?->id,
                'tipo_baja' => $tipoBaja,
                'motivo' => $motivo,
                'fecha_efectiva' => $fechaEfectiva,
                'estado' => EstadoCierreLaboral::Iniciado,
                'iniciado_por' => $actor->id,
                'observaciones' => $observaciones,
            ]);
        });

        $this->tareas->abrir(TipoTarea::FiniquitoPendiente, $cierre, [
            'titulo' => "Finiquito pendiente: {$colaborador->nombreCompleto()}",
            'descripcion' => sprintf('%s · fecha efectiva %s', $tipoBaja->etiqueta(), $cierre->fecha_efectiva->format('d/m/Y')),
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $colaborador,
            'permiso' => 'finiquitos.calcular',
            'accion' => 'calcular_finiquito',
            'vence_en' => $cierre->fecha_efectiva,
        ]);

        $this->auditoria->registrar('cierre_laboral_iniciado', $cierre, $actor, [
            'colaborador_id' => $colaborador->id,
            'tipo_baja' => $tipoBaja->value,
            'fecha_efectiva' => $cierre->fecha_efectiva->toDateString(),
            'evaluacion_id' => $evaluacion?->id,
        ]);

        return $cierre;
    }

    /**
     * Registra el aviso de término o la carta de renuncia firmada (evidencia
     * que la baja exige para aprobarse).
     */
    public function registrarAviso(CierreLaboral $cierre, UploadedFile $archivo, User $actor): CierreLaboral
    {
        $this->exigirNoFinal($cierre);
        $solicitud = $this->solicitud($cierre);

        DB::transaction(function () use ($cierre, $solicitud, $archivo, $actor): void {
            $this->solicitudes->adjuntarDocumento($solicitud, $archivo, $actor);
            $documentoId = $solicitud->documentos()->latest('id')->value('id');

            $cierre->update([
                'aviso_registrado_en' => now(),
                'aviso_documento_id' => $documentoId,
                'estado' => $this->avanzar($cierre, EstadoCierreLaboral::AvisoRegistrado),
            ]);
        });

        $this->auditoria->registrar('cierre_laboral_aviso', $cierre, $actor, ['nombre_archivo' => $archivo->getClientOriginalName()]);

        return $cierre->refresh();
    }

    /**
     * Genera el aviso de término con la plantilla "aviso_termino" (texto
     * provisto por Jurídico) para imprimir y firmar.
     */
    public function generarAviso(CierreLaboral $cierre, User $actor): GeneratedDocument
    {
        $this->exigirNoFinal($cierre);

        return $this->motor->generar($cierre->colaborador, 'aviso_termino', $actor, $this->variables($cierre), $cierre);
    }

    public function calcularFiniquito(CierreLaboral $cierre, User $actor, float $sueldoMensual, float $sueldoPendiente = 0): FiniquitoCalculo
    {
        $this->exigirNoFinal($cierre);
        $solicitud = $this->solicitud($cierre);
        $existente = FiniquitoCalculo::query()->where('solicitud_interna_id', $solicitud->id)->first();

        $finiquito = $existente === null
            ? $this->finiquitos->calcular($solicitud, $actor, $sueldoMensual, $sueldoPendiente)
            : $this->finiquitos->recalcular($existente, $actor, $sueldoMensual, $sueldoPendiente);

        $cierre->update(['estado' => $this->avanzar($cierre, EstadoCierreLaboral::FiniquitoEnProceso)]);

        return $finiquito;
    }

    public function finiquito(CierreLaboral $cierre): ?FiniquitoCalculo
    {
        return $cierre->solicitud_interna_id === null
            ? null
            : FiniquitoCalculo::query()->where('solicitud_interna_id', $cierre->solicitud_interna_id)->first();
    }

    public function exigirFiniquito(CierreLaboral $cierre): FiniquitoCalculo
    {
        $finiquito = $this->finiquito($cierre);

        if ($finiquito === null) {
            throw ValidationException::withMessages(['finiquito' => 'Primero calcula el finiquito de este cierre.']);
        }

        return $finiquito;
    }

    public function registrarFiniquitoFirmado(CierreLaboral $cierre, UploadedFile $archivo, User $actor): CierreLaboral
    {
        $this->exigirNoFinal($cierre);
        $this->finiquitos->subirFirmado($this->exigirFiniquito($cierre), $archivo, $actor);
        $cierre->update(['estado' => $this->avanzar($cierre, EstadoCierreLaboral::FiniquitoFirmado)]);

        return $cierre->refresh();
    }

    public function confirmarPago(CierreLaboral $cierre, User $actor, string $referencia): CierreLaboral
    {
        $this->exigirNoFinal($cierre);
        $finiquito = $this->exigirFiniquito($cierre);
        $this->finiquitos->confirmarPago($finiquito, $actor, $referencia);

        $cierre->update([
            'pago_confirmado_en' => now(),
            'pago_confirmado_por' => $actor->id,
            'referencia_pago' => $referencia,
            'estado' => $this->avanzar($cierre, EstadoCierreLaboral::Pagado),
        ]);

        $this->tareas->resolver(TipoTarea::FiniquitoPendiente, $cierre, $actor);

        return $cierre->refresh();
    }

    /**
     * Ejecuta la baja aprobando la solicitud (dispara BajaColaboradorService:
     * estatus inactivo, tokens/dispositivos revocados, vacante sincronizada),
     * termina el contrato vigente y marca el alta como "baja".
     */
    public function ejecutarBaja(CierreLaboral $cierre, User $actor): CierreLaboral
    {
        $this->exigirNoFinal($cierre);

        if ($cierre->estado === EstadoCierreLaboral::BajaEjecutada) {
            throw ValidationException::withMessages(['estado' => 'La baja ya fue ejecutada.']);
        }

        $finiquito = $this->exigirFiniquito($cierre);

        if (config('contratos.cierre.requiere_finiquito_firmado') && ! in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
            throw ValidationException::withMessages(['finiquito' => 'El finiquito debe estar firmado antes de ejecutar la baja.']);
        }

        if (config('contratos.cierre.requiere_pago_confirmado') && $finiquito->pagado_en === null) {
            throw ValidationException::withMessages(['finiquito' => 'Confirma el pago del finiquito antes de ejecutar la baja.']);
        }

        $solicitud = $this->solicitud($cierre);

        DB::transaction(function () use ($cierre, $solicitud, $actor): void {
            // Bloqueo: dos RH no pueden ejecutar la misma baja a la vez.
            $bloqueado = CierreLaboral::query()->lockForUpdate()->findOrFail($cierre->id);

            if ($bloqueado->estado === EstadoCierreLaboral::BajaEjecutada) {
                throw ValidationException::withMessages(['estado' => 'La baja ya fue ejecutada.']);
            }

            if ($solicitud->estado !== EstadoSolicitudInterna::Aprobada) {
                $this->solicitudes->aprobar($solicitud, $actor, 'Baja ejecutada desde cierre laboral.');
            }

            ContratoLaboral::query()
                ->where('colaborador_id', $cierre->colaborador_id)
                ->where('estado', EstadoContratoLaboral::Vigente->value)
                ->update(['estado' => EstadoContratoLaboral::Terminado->value]);

            $cierre->colaborador->forceFill(['fecha_baja' => $cierre->fecha_efectiva->toDateString()])->save();

            $cierre->update(['baja_ejecutada_en' => now(), 'estado' => EstadoCierreLaboral::BajaEjecutada]);
        });

        $this->auditoria->registrar('cierre_laboral_baja', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id]);

        return $cierre->refresh();
    }

    public function cerrarExpediente(CierreLaboral $cierre, User $actor): CierreLaboral
    {
        if ($cierre->estado !== EstadoCierreLaboral::BajaEjecutada) {
            throw ValidationException::withMessages(['estado' => 'El expediente se cierra después de ejecutar la baja.']);
        }

        DB::transaction(function () use ($cierre, $actor): void {
            $cierre->colaborador->forceFill([
                'expediente_cerrado_en' => now(),
                'expediente_cerrado_por' => $actor->id,
            ])->save();

            $solicitud = $this->solicitud($cierre);

            if ($solicitud->estado === EstadoSolicitudInterna::Aprobada) {
                $this->solicitudes->cerrar($solicitud, $actor, 'Expediente cerrado.');
            }

            $cierre->update(['expediente_cerrado_en' => now(), 'estado' => EstadoCierreLaboral::ExpedienteCerrado]);
        });

        $this->tareas->resolver([TipoTarea::FiniquitoPendiente], $cierre, $actor);
        $this->auditoria->registrar('cierre_laboral_expediente_cerrado', $cierre, $actor);

        return $cierre->refresh();
    }

    public function cancelar(CierreLaboral $cierre, User $actor, string $motivo): CierreLaboral
    {
        if (in_array($cierre->estado, [EstadoCierreLaboral::BajaEjecutada, EstadoCierreLaboral::ExpedienteCerrado, EstadoCierreLaboral::Cancelado], true)) {
            throw ValidationException::withMessages(['estado' => 'Este cierre ya no puede cancelarse.']);
        }

        DB::transaction(function () use ($cierre, $actor, $motivo): void {
            $solicitud = $this->solicitud($cierre);

            if ($solicitud->estado->puedeCancelarse()) {
                $this->solicitudes->cancelar($solicitud, $actor);
            }

            $cierre->update(['estado' => EstadoCierreLaboral::Cancelado, 'observaciones' => trim(($cierre->observaciones ?? '')."\nCancelado: {$motivo}")]);
        });

        $this->tareas->resolver(TipoTarea::FiniquitoPendiente, $cierre, $actor);
        $this->auditoria->registrar('cierre_laboral_cancelado', $cierre, $actor, ['motivo' => $motivo]);

        return $cierre->refresh();
    }

    /**
     * @param  array<string, mixed>  $filtros  estado?, per_page?
     * @return LengthAwarePaginator<int, CierreLaboral>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = CierreLaboral::query()->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id']);

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id'));
        }

        $estado = isset($filtros['estado']) ? (string) $filtros['estado'] : null;

        return $query
            ->when($estado, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(CierreLaboral $cierre, bool $detalle = false): array
    {
        $finiquito = $this->finiquito($cierre);

        $datosFiniquito = $finiquito !== null ? [
            'id' => $finiquito->id,
            'estado' => $finiquito->estado->value,
            'total_percepciones' => $finiquito->total_percepciones,
            'total_deducciones' => $finiquito->total_deducciones,
            'neto' => $finiquito->neto,
            'pagado_en' => $finiquito->pagado_en?->toIso8601String(),
            'documento_id' => $finiquito->generated_document_id,
        ] : null;

        if ($detalle && $finiquito !== null && $datosFiniquito !== null) {
            $datosFiniquito['desglose'] = $this->finiquitos->desglose($finiquito);
        }

        return [
            'id' => $cierre->id,
            'colaborador' => [
                'id' => $cierre->colaborador->id,
                'nombre' => $cierre->colaborador->nombreCompleto(),
                'numero_empleado' => $cierre->colaborador->numero_empleado,
            ],
            'solicitud_id' => $cierre->solicitud_interna_id,
            'evaluacion_id' => $cierre->evaluacion_id,
            'tipo_baja' => $cierre->tipo_baja->value,
            'tipo_baja_etiqueta' => $cierre->tipo_baja->etiqueta(),
            'motivo' => $cierre->motivo,
            'fecha_efectiva' => $cierre->fecha_efectiva->toDateString(),
            'estado' => $cierre->estado->value,
            'estado_etiqueta' => $cierre->estado->etiqueta(),
            'aviso_registrado_en' => $cierre->aviso_registrado_en?->toIso8601String(),
            'pago_confirmado_en' => $cierre->pago_confirmado_en?->toIso8601String(),
            'referencia_pago' => $cierre->referencia_pago,
            'baja_ejecutada_en' => $cierre->baja_ejecutada_en?->toIso8601String(),
            'expediente_cerrado_en' => $cierre->expediente_cerrado_en?->toIso8601String(),
            'finiquito' => $datosFiniquito,
        ];
    }

    private function solicitud(CierreLaboral $cierre): SolicitudInterna
    {
        $solicitud = $cierre->solicitud;

        if ($solicitud === null) {
            throw ValidationException::withMessages(['cierre' => 'El cierre no tiene solicitud de baja vinculada.']);
        }

        return $solicitud;
    }

    private function exigirNoFinal(CierreLaboral $cierre): void
    {
        if ($cierre->estado->esFinal()) {
            throw ValidationException::withMessages(['estado' => "El cierre ya está «{$cierre->estado->etiqueta()}»."]);
        }
    }

    /**
     * El estado del cierre solo avanza (nunca retrocede por registrar algo
     * fuera de orden, p. ej. subir el aviso después de calcular el finiquito).
     */
    private function avanzar(CierreLaboral $cierre, EstadoCierreLaboral $objetivo): EstadoCierreLaboral
    {
        $orden = array_flip(array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::cases()));

        return $orden[$objetivo->value] > $orden[$cierre->estado->value] ? $objetivo : $cierre->estado;
    }

    /**
     * @return array<string, string>
     */
    private function variables(CierreLaboral $cierre): array
    {
        return [
            'tipo_baja' => $cierre->tipo_baja->etiqueta(),
            'motivo_baja' => $cierre->motivo,
            'fecha_baja' => $cierre->fecha_efectiva->format('d/m/Y'),
            'folio_solicitud' => (string) $cierre->solicitud?->folio,
        ];
    }
}
