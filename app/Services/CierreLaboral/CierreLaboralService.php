<?php

namespace App\Services\CierreLaboral;

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\PrioridadTarea;
use App\Enums\ProcesoAprobacion;
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
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierre laboral (Etapa 6):
 *
 *   1. jefe/gerente SOLICITA (causa, motivo, fecha efectiva, evidencia)
 *   2. PREAUTORIZACIÓN operativa (superior según organigrama; implícita si
 *      quien solicita ya es superior de la persona)
 *   3. AUTORIZACIÓN FINAL de RH → documentos de la causa (plantillas de
 *      Jurídico/RH; sin plantilla = pendiente explícito)
 *   4. finiquito: RH genera / revisa / ajusta (auditado) / AUTORIZA
 *   5. regional de coordinación PROGRAMA el pago (fecha, monto, método)
 *   6. gerente cita al excolaborador → firma/huella del finiquito → pago
 *   7. cierre: baja (solo desde la fecha efectiva) + expediente cerrado
 *
 * Nada desactiva al colaborador antes de la autorización RH y la fecha
 * efectiva. NUNCA elimina datos: User/Colaborador/expediente/contratos/
 * evaluaciones/documentos/timeline se conservan; solo se bloquea el acceso.
 *
 * Reutiliza la solicitud interna de baja (folio, evidencia, historial,
 * finiquito) y BajaColaboradorService (bloqueo real, vacante).
 */
class CierreLaboralService
{
    public const PERMISO_GESTIONAR = 'cierres.gestionar';

    public const PERMISO_SOLICITAR = 'cierres.solicitar';

    public const PERMISO_PROGRAMAR_PAGO = 'cierres.programar_pago';

    public function __construct(
        private readonly SolicitudesService $solicitudes,
        private readonly FiniquitoService $finiquitos,
        private readonly MotorDocumentalService $motor,
        private readonly TareaService $tareas,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AprobacionService $aprobaciones,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly NotificadorRhService $notificador,
    ) {}

    /**
     * Solicitud de baja por el jefe/gerente (o RH).
     *
     * @param  array<string, mixed>  $datos  tipo_baja, motivo, fecha_efectiva, observaciones? (validado por IniciarCierreRequest).
     * @param  list<UploadedFile>  $evidencias
     */
    public function solicitar(Colaborador $colaborador, array $datos, User $actor, array $evidencias = []): CierreLaboral
    {
        $esRh = $actor->can(self::PERMISO_GESTIONAR);

        if (! $esRh && ! $actor->can(self::PERMISO_SOLICITAR)) {
            throw new AuthorizationException('No tienes permiso para solicitar bajas.');
        }

        $enCadena = $actor->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($actor->colaborador, $colaborador);

        if ($actor->colaborador_id === $colaborador->id || (! $enCadena && ! $this->alcance->alcanzaColaborador($actor, $colaborador))) {
            throw new AuthorizationException('Este colaborador está fuera de tu alcance.');
        }

        $tipoBaja = TipoBaja::from((string) $datos['tipo_baja']);

        if (! $esRh && ! in_array($tipoBaja->value, (array) config('ciclo_laboral.cierre.causas_solicitables', []), true)) {
            throw ValidationException::withMessages(['tipo_baja' => 'Elige una causa válida: renuncia, no renovación, bajo desempeño o baja inmediata.']);
        }

        $this->exigirSinCierreAbierto($colaborador);

        $motivo = trim((string) $datos['motivo']);
        $fechaEfectiva = (string) $datos['fecha_efectiva'];
        $observaciones = isset($datos['observaciones']) ? (string) $datos['observaciones'] : null;

        $cierre = DB::transaction(function () use ($colaborador, $actor, $tipoBaja, $motivo, $fechaEfectiva, $observaciones, $evidencias): CierreLaboral {
            Colaborador::query()->lockForUpdate()->findOrFail($colaborador->id);
            $this->exigirSinCierreAbierto($colaborador);

            // La solicitud de baja existente es el "expediente del trámite":
            // folio, historial, evidencia y finiquito.
            $solicitud = $this->solicitudes->crear($actor, [
                'tipo' => TipoSolicitudInterna::BajaColaborador->value,
                'colaborador_objetivo_id' => $colaborador->id,
                'tipo_baja' => $tipoBaja->value,
                'fecha_efectiva' => $fechaEfectiva,
                'motivo' => $motivo,
                'observaciones' => $observaciones,
            ]);

            foreach ($evidencias as $archivo) {
                $this->solicitudes->adjuntarDocumento($solicitud, $archivo, $actor);
            }

            $cierre = CierreLaboral::query()->create([
                'colaborador_id' => $colaborador->id,
                'solicitud_interna_id' => $solicitud->id,
                'tipo_baja' => $tipoBaja,
                'motivo' => $motivo,
                'fecha_efectiva' => $fechaEfectiva,
                'estado' => EstadoCierreLaboral::Solicitado,
                'iniciado_por' => $actor->id,
                'observaciones' => $observaciones,
            ]);

            $pre = $this->jerarquia->preautorizadorDeColaborador($colaborador, $actor);
            $abierta = $this->aprobaciones->abrir($cierre, ProcesoAprobacion::CierreLaboral, [
                'colaborador' => $colaborador,
                'solicitante' => $actor,
                'aprobador_colaborador' => $pre['aprobador'],
                'implicita' => $pre['implicita'],
                'motivo_omision' => $pre['motivo_omision'],
            ]);

            if ($abierta->etapa->value === 'autorizacion_rh') {
                $cierre->update(['estado' => EstadoCierreLaboral::PendienteRh]);
            }

            return $cierre;
        });

        $this->auditoria->registrar('cierre_laboral_solicitado', $cierre, $actor, [
            'colaborador_id' => $colaborador->id,
            'tipo_baja' => $tipoBaja->value,
            'fecha_efectiva' => $cierre->fecha_efectiva->toDateString(),
        ]);

        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    /**
     * Compatibilidad: POST /api/v1/rh/colaboradores/{colaborador}/cierres.
     *
     * @param  array<string, mixed>  $datos
     */
    public function iniciar(Colaborador $colaborador, array $datos, User $actor): CierreLaboral
    {
        return $this->solicitar($colaborador, $datos, $actor);
    }

    /**
     * No renovación autorizada desde la evaluación del periodo de prueba: la
     * preautorización (jefe) y la autorización RH ya están registradas en la
     * evaluación, así que el cierre nace autorizado.
     *
     * @param  array<string, mixed>  $datos
     */
    public function iniciarDesdeEvaluacion(Colaborador $colaborador, array $datos, User $actor, EvaluacionPeriodoPrueba $evaluacion): CierreLaboral
    {
        $this->exigirSinCierreAbierto($colaborador);
        $tipoBaja = TipoBaja::from((string) $datos['tipo_baja']);
        $motivo = (string) $datos['motivo'];
        $fechaEfectiva = (string) $datos['fecha_efectiva'];

        $cierre = DB::transaction(function () use ($colaborador, $actor, $evaluacion, $tipoBaja, $motivo, $fechaEfectiva): CierreLaboral {
            $solicitud = $this->solicitudes->crear($actor, [
                'tipo' => TipoSolicitudInterna::BajaColaborador->value,
                'colaborador_objetivo_id' => $colaborador->id,
                'tipo_baja' => $tipoBaja->value,
                'fecha_efectiva' => $fechaEfectiva,
                'motivo' => $motivo,
            ]);

            $this->solicitudes->marcarEnRevision($solicitud, $actor, 'No renovación autorizada por RH en la evaluación del periodo de prueba.');

            return CierreLaboral::query()->create([
                'colaborador_id' => $colaborador->id,
                'solicitud_interna_id' => $solicitud->id,
                'evaluacion_id' => $evaluacion->id,
                'tipo_baja' => $tipoBaja,
                'motivo' => $motivo,
                'fecha_efectiva' => $fechaEfectiva,
                'estado' => EstadoCierreLaboral::Iniciado,
                'iniciado_por' => $actor->id,
                'autorizado_rh_en' => now(),
                'autorizado_rh_por' => $actor->id,
            ]);
        });

        $this->auditoria->registrar('cierre_laboral_autorizado_rh', $cierre, $actor, [
            'colaborador_id' => $colaborador->id,
            'tipo_baja' => $tipoBaja->value,
            'evaluacion_id' => $evaluacion->id,
        ]);

        $this->generarDocumentosDeCausa($cierre, $actor, ContratoLaboralService::clavesConfiguradas('ciclo_laboral.periodo_prueba.documentos_no_renovacion'));
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    public function preautorizar(CierreLaboral $cierre, User $actor, ?string $comentario = null): CierreLaboral
    {
        $cierre = DB::transaction(function () use ($cierre, $actor, $comentario): CierreLaboral {
            $cierre = $this->bloquear($cierre, [EstadoCierreLaboral::Solicitado]);
            $this->aprobaciones->preautorizar($cierre, ProcesoAprobacion::CierreLaboral, $actor, $comentario);
            $cierre->update(['estado' => EstadoCierreLaboral::PendienteRh]);

            return $cierre;
        });

        $this->sincronizarPendientes($cierre, $actor);

        return $cierre->refresh();
    }

    /**
     * Autorización final de RH: a partir de aquí corre el finiquito. La baja
     * efectiva sigue esperando la fecha efectiva y el pago.
     */
    public function autorizarRh(CierreLaboral $cierre, User $actor, ?string $comentario = null): CierreLaboral
    {
        $cierre = DB::transaction(function () use ($cierre, $actor, $comentario): CierreLaboral {
            $cierre = $this->bloquear($cierre, [EstadoCierreLaboral::PendienteRh]);
            $this->aprobaciones->autorizarRh($cierre, ProcesoAprobacion::CierreLaboral, $actor, $comentario);

            $cierre->update([
                'estado' => EstadoCierreLaboral::Iniciado,
                'autorizado_rh_en' => now(),
                'autorizado_rh_por' => $actor->id,
            ]);

            $this->solicitudes->marcarEnRevision($this->solicitud($cierre), $actor, 'Baja autorizada por RH.');

            return $cierre;
        });

        $this->auditoria->registrar('cierre_laboral_autorizado_rh', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id]);

        $this->generarDocumentosDeCausa($cierre, $actor, ContratoLaboralService::clavesConfiguradas("ciclo_laboral.cierre.documentos_por_causa.{$cierre->tipo_baja->value}"));
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    public function rechazar(CierreLaboral $cierre, User $actor, string $motivo): CierreLaboral
    {
        $cierre = DB::transaction(function () use ($cierre, $actor, $motivo): CierreLaboral {
            $cierre = $this->bloquear($cierre, [EstadoCierreLaboral::Solicitado, EstadoCierreLaboral::PendienteRh]);
            $this->aprobaciones->rechazar($cierre, ProcesoAprobacion::CierreLaboral, $actor, $motivo);

            $solicitud = $this->solicitud($cierre);

            if ($solicitud->estado->puedeCancelarse()) {
                $this->solicitudes->cancelar($solicitud, $actor);
            }

            $cierre->update(['estado' => EstadoCierreLaboral::Rechazado, 'rechazado_en' => now(), 'motivo_rechazo' => $motivo]);

            return $cierre;
        });

        $this->auditoria->registrar('cierre_laboral_rechazado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'motivo' => $motivo]);
        $this->sincronizarPendientes($cierre, $actor);

        return $cierre->refresh();
    }

    public function devolver(CierreLaboral $cierre, User $actor, string $motivo): CierreLaboral
    {
        $cierre = DB::transaction(function () use ($cierre, $actor, $motivo): CierreLaboral {
            $cierre = $this->bloquear($cierre, [EstadoCierreLaboral::PendienteRh]);
            $this->aprobaciones->devolver($cierre, ProcesoAprobacion::CierreLaboral, $actor, $motivo);
            $cierre->update(['estado' => EstadoCierreLaboral::Solicitado]);

            return $cierre;
        });

        $this->auditoria->registrar('cierre_laboral_devuelto', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'motivo' => $motivo]);
        $this->sincronizarPendientes($cierre, $actor);

        return $cierre->refresh();
    }

    /**
     * Registra el aviso de término o la carta de renuncia firmada (evidencia
     * que la baja exige para aprobarse).
     */
    public function registrarAviso(CierreLaboral $cierre, UploadedFile $archivo, User $actor): CierreLaboral
    {
        $this->exigirAutorizado($cierre);
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

        $this->auditoria->registrar('cierre_laboral_aviso', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'nombre_archivo' => $archivo->getClientOriginalName()]);

        return $cierre->refresh();
    }

    /**
     * Genera el aviso de término con la plantilla "aviso_termino" (texto
     * provisto por Jurídico) para imprimir y firmar.
     */
    public function generarAviso(CierreLaboral $cierre, User $actor): GeneratedDocument
    {
        $this->exigirAutorizado($cierre);

        return $this->motor->generar($cierre->colaborador, 'aviso_termino', $actor, $this->variables($cierre), $cierre);
    }

    public function calcularFiniquito(CierreLaboral $cierre, User $actor, float $sueldoMensual, float $sueldoPendiente = 0): FiniquitoCalculo
    {
        $this->exigirAutorizado($cierre);

        if ($cierre->finiquito_autorizado_en !== null) {
            throw ValidationException::withMessages(['finiquito' => 'El finiquito ya fue autorizado; para corregirlo, RH debe registrar un ajuste auditado.']);
        }

        $solicitud = $this->solicitud($cierre);
        $existente = FiniquitoCalculo::query()->where('solicitud_interna_id', $solicitud->id)->first();

        $finiquito = $existente === null
            ? $this->finiquitos->calcular($solicitud, $actor, $sueldoMensual, $sueldoPendiente)
            : $this->finiquitos->recalcular($existente, $actor, $sueldoMensual, $sueldoPendiente);

        $cierre->update(['estado' => $this->avanzar($cierre, EstadoCierreLaboral::FiniquitoEnProceso)]);
        $this->auditoria->registrar('finiquito_calculado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'neto' => $finiquito->neto]);
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $finiquito;
    }

    /**
     * RH autoriza el finiquito (monto final). A partir de aquí lo programa
     * el regional de coordinación.
     */
    public function autorizarFiniquito(CierreLaboral $cierre, User $actor): CierreLaboral
    {
        if (! $actor->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH) || ! $actor->can('finiquitos.revisar')) {
            throw new AuthorizationException('Solo RH autoriza el finiquito.');
        }

        $cierre = DB::transaction(function () use ($cierre, $actor): CierreLaboral {
            $cierre = $this->bloquear($cierre, [EstadoCierreLaboral::Iniciado, EstadoCierreLaboral::AvisoRegistrado, EstadoCierreLaboral::FiniquitoEnProceso]);
            $finiquito = $this->exigirFiniquito($cierre);

            if ($finiquito->estado === EstadoFiniquito::Borrador) {
                $this->finiquitos->aprobarCalculo($finiquito, $actor);
            }

            $cierre->update([
                'estado' => EstadoCierreLaboral::FiniquitoAutorizado,
                'finiquito_autorizado_en' => now(),
                'finiquito_autorizado_por' => $actor->id,
            ]);

            return $cierre;
        });

        $this->auditoria->registrar('finiquito_autorizado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'neto' => $this->finiquito($cierre)?->neto]);
        $this->sincronizarPendientes($cierre, $actor);

        return $cierre->refresh();
    }

    /**
     * Regional de coordinación: programa el pago según flujo de efectivo.
     *
     * @param  array{fecha: string, monto?: float|int|string|null, metodo: string, responsable_user_id?: int|null, observaciones?: string|null}  $datos
     */
    public function programarPago(CierreLaboral $cierre, User $actor, array $datos): CierreLaboral
    {
        $cierre->loadMissing('colaborador');

        if (! $actor->can(self::PERMISO_PROGRAMAR_PAGO) || ! $this->alcance->alcanzaColaborador($actor, $cierre->colaborador)) {
            throw new AuthorizationException('Solo el regional de coordinación (o RH) programa el pago del finiquito.');
        }

        $cierre = DB::transaction(function () use ($cierre, $actor, $datos): CierreLaboral {
            $cierre = $this->bloquear($cierre, [EstadoCierreLaboral::FiniquitoAutorizado, EstadoCierreLaboral::PagoProgramado]);
            $finiquito = $this->exigirFiniquito($cierre);

            $cierre->update([
                'estado' => EstadoCierreLaboral::PagoProgramado,
                'pago_programado_para' => $datos['fecha'],
                'pago_monto' => $datos['monto'] ?? $finiquito->neto,
                'pago_metodo' => $datos['metodo'],
                'pago_responsable_user_id' => $datos['responsable_user_id'] ?? $actor->id,
                'pago_observaciones' => $datos['observaciones'] ?? null,
                'pago_programado_por' => $actor->id,
                'pago_programado_en' => now(),
            ]);

            return $cierre;
        });

        $this->auditoria->registrar('finiquito_pago_programado', $cierre, $actor, [
            'colaborador_id' => $cierre->colaborador_id,
            'fecha' => $cierre->pago_programado_para?->toDateString(),
            'monto' => $cierre->pago_monto,
            'metodo' => $cierre->pago_metodo,
        ]);
        $this->sincronizarPendientes($cierre, $actor);

        return $cierre->refresh();
    }

    /**
     * El gerente registra la cita con el excolaborador para firma y pago.
     */
    public function registrarCita(CierreLaboral $cierre, User $actor, string $fecha): CierreLaboral
    {
        $this->exigirOperacion($cierre, $actor);

        $cierre = DB::transaction(function () use ($cierre, $actor, $fecha): CierreLaboral {
            $cierre = $this->bloquear($cierre, [EstadoCierreLaboral::PagoProgramado]);
            $cierre->update(['cita_firma_en' => $fecha, 'cita_registrada_por' => $actor->id]);

            return $cierre;
        });

        $this->auditoria->registrar('finiquito_cita', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'cita' => $fecha]);

        return $cierre->refresh();
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

    /**
     * Firma y huella del finiquito (después de programar el pago).
     */
    public function registrarFiniquitoFirmado(CierreLaboral $cierre, UploadedFile $archivo, User $actor): CierreLaboral
    {
        $this->exigirOperacion($cierre, $actor);
        $this->exigirEstado($cierre, [EstadoCierreLaboral::PagoProgramado, EstadoCierreLaboral::FiniquitoFirmado, EstadoCierreLaboral::Pagado]);
        $this->finiquitos->subirFirmado($this->exigirFiniquito($cierre), $archivo, $actor);
        $cierre->update(['estado' => $this->avanzar($cierre, EstadoCierreLaboral::FiniquitoFirmado)]);
        $this->auditoria->registrar('finiquito_firmado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id]);
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    public function confirmarPago(CierreLaboral $cierre, User $actor, string $referencia): CierreLaboral
    {
        $this->exigirOperacion($cierre, $actor);
        $this->exigirEstado($cierre, [EstadoCierreLaboral::PagoProgramado, EstadoCierreLaboral::FiniquitoFirmado]);
        $finiquito = $this->exigirFiniquito($cierre);
        $this->finiquitos->confirmarPago($finiquito, $actor, $referencia);

        $cierre->update([
            'pago_confirmado_en' => now(),
            'pago_confirmado_por' => $actor->id,
            'referencia_pago' => $referencia,
            'estado' => $this->avanzar($cierre, EstadoCierreLaboral::Pagado),
        ]);

        $this->auditoria->registrar('finiquito_pagado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'referencia' => $referencia]);
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    /**
     * Ejecuta la baja (estatus inactivo, tokens/dispositivos revocados,
     * vacante sincronizada) — solo con RH autorizado, finiquito firmado y
     * pagado, y a partir de la fecha efectiva.
     */
    public function ejecutarBaja(CierreLaboral $cierre, User $actor): CierreLaboral
    {
        $this->exigirNoFinal($cierre);
        $this->exigirAutorizado($cierre);

        if ($cierre->estado === EstadoCierreLaboral::BajaEjecutada) {
            throw ValidationException::withMessages(['estado' => 'La baja ya fue ejecutada.']);
        }

        if ($cierre->fecha_efectiva->isAfter(now()->startOfDay())) {
            throw ValidationException::withMessages(['fecha_efectiva' => sprintf('La baja no puede ejecutarse antes de su fecha efectiva (%s).', $cierre->fecha_efectiva->format('d/m/Y'))]);
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

        $this->auditoria->registrar('cierre_laboral_expediente_cerrado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id]);
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    /**
     * Cierre laboral completo en un paso (baja + expediente cerrado).
     */
    public function cerrar(CierreLaboral $cierre, User $actor): CierreLaboral
    {
        if ($cierre->estado !== EstadoCierreLaboral::BajaEjecutada) {
            $cierre = $this->ejecutarBaja($cierre, $actor);
        }

        return $this->cerrarExpediente($cierre, $actor);
    }

    public function cancelar(CierreLaboral $cierre, User $actor, string $motivo): CierreLaboral
    {
        if (in_array($cierre->estado, [EstadoCierreLaboral::BajaEjecutada, EstadoCierreLaboral::ExpedienteCerrado, EstadoCierreLaboral::Cancelado, EstadoCierreLaboral::Rechazado], true)) {
            throw ValidationException::withMessages(['estado' => 'Este cierre ya no puede cancelarse.']);
        }

        DB::transaction(function () use ($cierre, $actor, $motivo): void {
            $solicitud = $this->solicitud($cierre);

            if ($solicitud->estado->puedeCancelarse()) {
                $this->solicitudes->cancelar($solicitud, $actor);
            }

            $this->aprobaciones->cancelarPendientes($cierre, ProcesoAprobacion::CierreLaboral, $actor, $motivo);
            $cierre->update(['estado' => EstadoCierreLaboral::Cancelado, 'observaciones' => trim(($cierre->observaciones ?? '')."\nCancelado: {$motivo}")]);
        });

        $this->auditoria->registrar('cierre_laboral_cancelado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'motivo' => $motivo]);
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    /**
     * Cierres pagados cuya fecha efectiva ya llegó: pendiente "por concluir"
     * (scheduler diario, idempotente).
     */
    public function revisarFechasEfectivas(): int
    {
        $abiertos = 0;

        CierreLaboral::query()
            ->where('estado', EstadoCierreLaboral::Pagado->value)
            ->whereDate('fecha_efectiva', '<=', now()->toDateString())
            ->with('colaborador')
            ->each(function (CierreLaboral $cierre) use (&$abiertos): void {
                $tarea = $this->tareas->abrir(TipoTarea::CierrePorConcluir, $cierre, [
                    'titulo' => sprintf('Concluir cierre laboral: %s', $cierre->colaborador->nombreCompleto()),
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $cierre->colaborador,
                    'permiso' => self::PERMISO_GESTIONAR,
                    'accion' => 'cerrar_cierre',
                ]);

                if ($tarea !== null && $tarea->wasRecentlyCreated) {
                    $abiertos++;
                }
            });

        return $abiertos;
    }

    /**
     * @param  array<string, mixed>  $filtros  estado?, colaborador_id?, per_page?
     * @return LengthAwarePaginator<int, CierreLaboral>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = CierreLaboral::query()->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,jefe_id']);

        // El alcance se aplica SIEMPRE antes del filtro por colaborador: pedir
        // el id de alguien fuera del alcance devuelve una lista vacía.
        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id'));
        }

        $estado = isset($filtros['estado']) ? (string) $filtros['estado'] : null;

        return $query
            ->when($estado === 'abiertos', fn (Builder $q) => $q->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos())))
            ->when($estado !== null && $estado !== 'abiertos', fn (Builder $q) => $q->where('estado', $estado))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('colaborador_id', (int) $v))
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    /**
     * Acciones que el usuario puede ejecutar AHORA sobre el cierre.
     *
     * @return list<array{clave: string, etiqueta: string, tipo: string}>
     */
    public function accionesPermitidas(CierreLaboral $cierre, User $usuario): array
    {
        $cierre->loadMissing('colaborador');

        if (! $this->alcance->alcanzaColaborador($usuario, $cierre->colaborador) && ! ($usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $cierre->colaborador))) {
            return [];
        }

        $acciones = [];
        $pendiente = $this->aprobaciones->pendiente($cierre, ProcesoAprobacion::CierreLaboral);
        $rh = $usuario->can(self::PERMISO_GESTIONAR);
        $operacion = $rh || $usuario->can(self::PERMISO_SOLICITAR);

        switch ($cierre->estado) {
            case EstadoCierreLaboral::Solicitado:
                if ($pendiente !== null && $this->jerarquia->puedePreautorizar($usuario, $pendiente)) {
                    $acciones[] = ['clave' => 'preautorizar', 'etiqueta' => 'Preautorizar baja', 'tipo' => 'primaria'];
                    $acciones[] = ['clave' => 'rechazar', 'etiqueta' => 'Rechazar', 'tipo' => 'peligro'];
                }
                break;
            case EstadoCierreLaboral::PendienteRh:
                if ($pendiente !== null && $this->jerarquia->puedeAutorizarRh($usuario, $pendiente)) {
                    $acciones[] = ['clave' => 'autorizar_rh', 'etiqueta' => 'Autorizar baja', 'tipo' => 'primaria'];
                    $acciones[] = ['clave' => 'devolver', 'etiqueta' => 'Devolver', 'tipo' => 'secundaria'];
                    $acciones[] = ['clave' => 'rechazar', 'etiqueta' => 'Rechazar', 'tipo' => 'peligro'];
                }
                break;
            case EstadoCierreLaboral::Iniciado:
            case EstadoCierreLaboral::AvisoRegistrado:
            case EstadoCierreLaboral::FiniquitoEnProceso:
                if ($operacion) {
                    $acciones[] = ['clave' => 'registrar_aviso', 'etiqueta' => 'Registrar renuncia / aviso firmado', 'tipo' => 'secundaria'];
                }
                if ($usuario->can('finiquitos.calcular')) {
                    $acciones[] = ['clave' => 'calcular_finiquito', 'etiqueta' => 'Calcular finiquito', 'tipo' => 'secundaria'];
                }
                if ($this->finiquito($cierre) !== null && $usuario->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH) && $usuario->can('finiquitos.revisar')) {
                    $acciones[] = ['clave' => 'autorizar_finiquito', 'etiqueta' => 'Autorizar finiquito', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCierreLaboral::FiniquitoAutorizado:
                if ($usuario->can(self::PERMISO_PROGRAMAR_PAGO)) {
                    $acciones[] = ['clave' => 'programar_pago', 'etiqueta' => 'Programar pago', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCierreLaboral::PagoProgramado:
            case EstadoCierreLaboral::FiniquitoFirmado:
                if ($operacion) {
                    $acciones[] = ['clave' => 'registrar_cita', 'etiqueta' => 'Registrar cita', 'tipo' => 'secundaria'];
                    if ($cierre->estado === EstadoCierreLaboral::PagoProgramado) {
                        $acciones[] = ['clave' => 'finiquito_firmado', 'etiqueta' => 'Registrar firma y huella', 'tipo' => 'primaria'];
                    }
                    $acciones[] = ['clave' => 'confirmar_pago', 'etiqueta' => 'Confirmar pago', 'tipo' => 'primaria'];
                }
                break;
            case EstadoCierreLaboral::Pagado:
            case EstadoCierreLaboral::BajaEjecutada:
                if ($rh) {
                    $acciones[] = ['clave' => 'cerrar', 'etiqueta' => 'Concluir cierre laboral', 'tipo' => 'primaria'];
                }
                break;
            default:
                break;
        }

        if ($rh && ! $cierre->estado->esFinal() && $cierre->estado !== EstadoCierreLaboral::BajaEjecutada) {
            $acciones[] = ['clave' => 'cancelar', 'etiqueta' => 'Cancelar cierre', 'tipo' => 'peligro'];
        }

        return $acciones;
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(CierreLaboral $cierre, bool $detalle = false, ?User $viewer = null): array
    {
        $finiquito = $this->finiquito($cierre);
        $cierre->loadMissing(['colaborador.sucursalPrincipal', 'iniciadoPor', 'pagoResponsable']);

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

        $documentos = GeneratedDocument::query()
            ->where('documentable_type', $cierre->getMorphClass())
            ->where('documentable_id', $cierre->id)
            ->orderBy('id')
            ->get(['id', 'titulo', 'clave_plantilla', 'estado_flujo'])
            ->map(fn (GeneratedDocument $d) => ['id' => $d->id, 'titulo' => $d->titulo, 'clave' => $d->clave_plantilla, 'estado' => $d->estado_flujo?->etiqueta()])
            ->values()
            ->all();

        return [
            'id' => $cierre->id,
            'colaborador' => [
                'id' => $cierre->colaborador->id,
                'nombre' => $cierre->colaborador->nombreCompleto(),
                'numero_empleado' => $cierre->colaborador->numero_empleado,
                'sucursal' => $cierre->colaborador->sucursalPrincipal?->nombre,
            ],
            'solicitud_id' => $cierre->solicitud_interna_id,
            'evaluacion_id' => $cierre->evaluacion_id,
            'tipo_baja' => $cierre->tipo_baja->value,
            'tipo_baja_etiqueta' => $cierre->tipo_baja->etiqueta(),
            'motivo' => $cierre->motivo,
            'fecha_efectiva' => $cierre->fecha_efectiva->toDateString(),
            'estado' => $cierre->estado->value,
            'estado_etiqueta' => $cierre->estado->etiqueta(),
            'solicitado_por' => $cierre->iniciadoPor?->nombreCompleto(),
            'solicitado_en' => $cierre->created_at?->toIso8601String(),
            'autorizado_rh_en' => $cierre->autorizado_rh_en?->toIso8601String(),
            'rechazado_en' => $cierre->rechazado_en?->toIso8601String(),
            'motivo_rechazo' => $cierre->motivo_rechazo,
            'aviso_registrado_en' => $cierre->aviso_registrado_en?->toIso8601String(),
            'finiquito_autorizado_en' => $cierre->finiquito_autorizado_en?->toIso8601String(),
            'pago' => [
                'programado_para' => $cierre->pago_programado_para?->toDateString(),
                'monto' => $cierre->pago_monto,
                'metodo' => $cierre->pago_metodo,
                'responsable' => $cierre->pagoResponsable?->nombreCompleto(),
                'observaciones' => $cierre->pago_observaciones,
                'cita_firma_en' => $cierre->cita_firma_en?->toIso8601String(),
                'confirmado_en' => $cierre->pago_confirmado_en?->toIso8601String(),
                'referencia' => $cierre->referencia_pago,
            ],
            'pago_confirmado_en' => $cierre->pago_confirmado_en?->toIso8601String(),
            'referencia_pago' => $cierre->referencia_pago,
            'baja_ejecutada_en' => $cierre->baja_ejecutada_en?->toIso8601String(),
            'expediente_cerrado_en' => $cierre->expediente_cerrado_en?->toIso8601String(),
            'finiquito' => $datosFiniquito,
            'documentos' => $documentos,
            'aprobaciones' => $this->aprobaciones->resumen($cierre, ProcesoAprobacion::CierreLaboral),
            'acciones_permitidas' => $viewer !== null ? $this->accionesPermitidas($cierre, $viewer) : [],
        ];
    }

    /**
     * Documentos que corresponden a la causa (plantillas de Jurídico/RH).
     * Sin plantilla: pendiente explícito, nunca un documento inventado.
     *
     * @param  list<string>  $claves
     */
    private function generarDocumentosDeCausa(CierreLaboral $cierre, User $actor, array $claves): void
    {
        $cierre->loadMissing('colaborador');

        foreach ($claves as $clave) {
            $yaExiste = GeneratedDocument::query()
                ->where('documentable_type', $cierre->getMorphClass())
                ->where('documentable_id', $cierre->id)
                ->where('clave_plantilla', $clave)
                ->exists();

            if ($yaExiste) {
                continue;
            }

            try {
                $this->motor->generar($cierre->colaborador, $clave, $actor, $this->variables($cierre), $cierre);
            } catch (ValidationException $e) {
                $this->tareas->abrir(TipoTarea::PlantillaFaltante, $cierre, [
                    'titulo' => sprintf('Falta la plantilla «%s» para el cierre de %s', config("ciclo_laboral.plantillas.{$clave}", $clave), $cierre->colaborador->nombreCompleto()),
                    'descripcion' => collect($e->errors())->flatten()->implode(' '),
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $cierre->colaborador,
                    'permiso' => 'plantillas_documentales.administrar',
                    'accion' => 'cargar_plantilla',
                    'datos' => ['clave' => $clave],
                ]);
            }
        }
    }

    /**
     * Mantiene la bandeja coherente con el estado del cierre: resuelve lo
     * que ya no aplica y abre el pendiente del siguiente responsable.
     */
    private function sincronizarPendientes(CierreLaboral $cierre, ?User $actor): void
    {
        $cierre->loadMissing('colaborador.jefe.user');
        $colaborador = $cierre->colaborador;
        $nombre = $colaborador->nombreCompleto();
        $tiposCierre = [
            TipoTarea::CierrePreautorizacion, TipoTarea::CierreAutorizacionRh, TipoTarea::FiniquitoPendiente,
            TipoTarea::FiniquitoPorAutorizar, TipoTarea::PagoPorProgramar, TipoTarea::CitaFiniquito, TipoTarea::CierrePorConcluir,
        ];
        $this->tareas->resolver($tiposCierre, $cierre, $actor);

        $base = ['colaborador' => $colaborador, 'prioridad' => PrioridadTarea::Alta, 'vence_en' => $cierre->fecha_efectiva];

        switch ($cierre->estado) {
            case EstadoCierreLaboral::Solicitado:
                $pendiente = $this->aprobaciones->pendiente($cierre, ProcesoAprobacion::CierreLaboral);
                $aprobador = $pendiente?->aprobadorColaborador?->user;
                $this->tareas->abrir(TipoTarea::CierrePreautorizacion, $cierre, [...$base,
                    'titulo' => "Preautorizar baja: {$nombre}",
                    'usuario' => $aprobador,
                    'permiso' => $aprobador === null ? OrganizacionJerarquiaService::PERMISO_PREAUTORIZAR : null,
                    'accion' => 'preautorizar_cierre',
                ]);
                if ($aprobador !== null) {
                    $this->notificador->notificar([$aprobador], 'cierre_preautorizacion', 'Baja por preautorizar', "Se solicitó la baja de {$nombre} ({$cierre->tipo_baja->etiqueta()}).", $cierre, 'preautorizar_cierre', 'alta');
                }
                break;
            case EstadoCierreLaboral::PendienteRh:
                $this->tareas->abrir(TipoTarea::CierreAutorizacionRh, $cierre, [...$base,
                    'titulo' => "Autorizar baja: {$nombre}",
                    'descripcion' => sprintf('%s · fecha efectiva %s', $cierre->tipo_baja->etiqueta(), $cierre->fecha_efectiva->format('d/m/Y')),
                    'permiso' => OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH,
                    'accion' => 'autorizar_cierre',
                ]);
                $this->notificador->notificar($this->notificador->responsablesDe($colaborador, OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH), 'cierre_autorizacion_rh', 'Baja pendiente de tu autorización', "La baja de {$nombre} está preautorizada.", $cierre, 'autorizar_cierre', 'alta');
                break;
            case EstadoCierreLaboral::Iniciado:
            case EstadoCierreLaboral::AvisoRegistrado:
                $this->tareas->abrir(TipoTarea::FiniquitoPendiente, $cierre, [...$base,
                    'titulo' => "Finiquito pendiente: {$nombre}",
                    'permiso' => 'finiquitos.calcular',
                    'accion' => 'calcular_finiquito',
                ]);
                break;
            case EstadoCierreLaboral::FiniquitoEnProceso:
                $this->tareas->abrir(TipoTarea::FiniquitoPorAutorizar, $cierre, [...$base,
                    'titulo' => "Autorizar finiquito: {$nombre}",
                    'permiso' => OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH,
                    'accion' => 'autorizar_finiquito',
                ]);
                break;
            case EstadoCierreLaboral::FiniquitoAutorizado:
                $this->tareas->abrir(TipoTarea::PagoPorProgramar, $cierre, [...$base,
                    'titulo' => "Programar pago de finiquito: {$nombre}",
                    'permiso' => self::PERMISO_PROGRAMAR_PAGO,
                    'accion' => 'programar_pago',
                ]);
                $this->notificador->notificar($this->notificador->responsablesDe($colaborador, self::PERMISO_PROGRAMAR_PAGO), 'pago_por_programar', 'Pago de finiquito por programar', "El finiquito de {$nombre} está autorizado.", $cierre, 'programar_pago', 'alta');
                break;
            case EstadoCierreLaboral::PagoProgramado:
            case EstadoCierreLaboral::FiniquitoFirmado:
                $jefe = $colaborador->jefe?->user;
                $this->tareas->abrir(TipoTarea::CitaFiniquito, $cierre, [...$base,
                    'titulo' => "Citar a {$nombre}: firma de finiquito y pago",
                    'descripcion' => sprintf('Pago programado para %s.', $cierre->pago_programado_para?->format('d/m/Y') ?? 'por definir'),
                    'usuario' => $jefe,
                    'permiso' => $jefe === null ? self::PERMISO_SOLICITAR : null,
                    'accion' => 'citar_excolaborador',
                ]);
                if ($jefe !== null && $cierre->estado === EstadoCierreLaboral::PagoProgramado) {
                    $this->notificador->notificar([$jefe], 'cita_finiquito', 'Excolaborador por citar', "El pago del finiquito de {$nombre} está programado: cítalo para firma y pago.", $cierre, 'citar_excolaborador', 'alta');
                }
                break;
            default:
                break;
        }
    }

    private function exigirSinCierreAbierto(Colaborador $colaborador): void
    {
        $abierto = CierreLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos()))
            ->exists();

        if ($abierto) {
            throw ValidationException::withMessages(['colaborador' => 'El colaborador ya tiene un cierre laboral en proceso.']);
        }
    }

    /**
     * @param  list<EstadoCierreLaboral>  $permitidos
     */
    private function bloquear(CierreLaboral $cierre, array $permitidos): CierreLaboral
    {
        $bloqueado = CierreLaboral::query()->lockForUpdate()->findOrFail($cierre->id);
        $this->exigirEstado($bloqueado, $permitidos);

        return $bloqueado;
    }

    /**
     * @param  list<EstadoCierreLaboral>  $permitidos
     */
    private function exigirEstado(CierreLaboral $cierre, array $permitidos): void
    {
        if (! in_array($cierre->estado, $permitidos, true)) {
            throw ValidationException::withMessages(['estado' => "El cierre está «{$cierre->estado->etiqueta()}»: esta acción no aplica ahora."]);
        }
    }

    private function exigirAutorizado(CierreLaboral $cierre): void
    {
        $this->exigirNoFinal($cierre);

        if (! $cierre->estado->autorizadoPorRh()) {
            throw ValidationException::withMessages(['estado' => 'La baja todavía no tiene la autorización final de RH.']);
        }
    }

    private function exigirOperacion(CierreLaboral $cierre, User $actor): void
    {
        $cierre->loadMissing('colaborador');
        $enCadena = $actor->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($actor->colaborador, $cierre->colaborador);

        if ((! $actor->can(self::PERMISO_GESTIONAR) && ! $actor->can(self::PERMISO_SOLICITAR)) || (! $enCadena && ! $this->alcance->alcanzaColaborador($actor, $cierre->colaborador))) {
            throw new AuthorizationException('No puedes operar este cierre laboral.');
        }
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
