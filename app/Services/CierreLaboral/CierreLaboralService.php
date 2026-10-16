<?php

namespace App\Services\CierreLaboral;

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\FormaPagoFiniquito;
use App\Enums\PrioridadTarea;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoBaja;
use App\Enums\TipoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Exceptions\DatosDocumentoFaltantesException;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Administracion\AccesoCuentaService;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use App\Support\Paginacion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cierre laboral (Etapa 6):
 *
 *   1. el jefe directo (cadena de mando del organigrama) o RH INICIA la baja
 *      (causa, motivo, fecha efectiva) y queda APLICADA DE INMEDIATO: sin
 *      preautorización, sin autorización de RH y sin archivo de evidencia
 *      («a veces algo grave pasó: la baja es inmediata»).
 *   2. documentos de la causa generados por el sistema (plantillas de
 *      Jurídico/RH; sin plantilla = pendiente explícito): la renuncia sigue
 *      Generado → Impreso → Firmado físicamente
 *   4. finiquito: RH genera / revisa / ajusta (auditado)
 *   5. regional de coordinación PROGRAMA el pago (fecha, monto, método)
 *   6. gerente cita al excolaborador → firma/huella del finiquito → pago
 *   7. cierre: baja (solo desde la fecha efectiva) + expediente cerrado
 *
 * Al INICIAR la baja se suspende de inmediato el acceso de la persona
 * (cuenta inactiva, AccesoCuentaService) y se avisa a regionales y RH, que
 * pueden rehabilitarlo; si RH cancela el cierre, el acceso vuelve solo. Su
 * estatus LABORAL (inactivo, fecha de baja, vacante) se ejecuta al concluir
 * el cierre (ejecutarBaja). NUNCA elimina datos: User/Colaborador/expediente/contratos/
 * evaluaciones/documentos/timeline se conservan para un posible reingreso.
 *
 * Reutiliza la solicitud interna de baja (folio, evidencia, historial,
 * finiquito) y BajaColaboradorService (bloqueo real, vacante).
 */
class CierreLaboralService
{
    public const PERMISO_GESTIONAR = 'cierres.gestionar';

    public const PERMISO_SOLICITAR = 'cierres.solicitar';

    public const PERMISO_PROGRAMAR_PAGO = 'cierres.programar_pago';

    /** Prefijo del motivo de bloqueo: identifica una suspensión puesta por el trámite de baja. */
    public const MOTIVO_SUSPENSION = 'Baja en trámite:';

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
        private readonly AccesoCuentaService $acceso,
        private readonly DocumentoProcesoService $documentosProceso,
    ) {}

    /**
     * Baja iniciada por el jefe directo (cadena de mando del organigrama) o
     * por RH: se APLICA DE INMEDIATO, sin autorización posterior de RH ni
     * archivo de evidencia. En la misma transacción: trámite (solicitud de
     * baja) en revisión, cierre en «Baja aplicada · documentos pendientes» y
     * accesos suspendidos. El sistema genera los documentos de la causa (la
     * renuncia incluida: Generado → Impreso → Firmado físicamente); lo que
     * sigue es el finiquito.
     *
     * @param  array<string, mixed>  $datos  tipo_baja, motivo, fecha_efectiva, observaciones? (validado por SolicitarCierreRequest/IniciarCierreRequest).
     */
    public function solicitar(Colaborador $colaborador, array $datos, User $actor): CierreLaboral
    {
        $esRh = $actor->can(self::PERMISO_GESTIONAR);

        if (! $esRh && ! $actor->can(self::PERMISO_SOLICITAR)) {
            throw new AuthorizationException('No tienes permiso para solicitar bajas.');
        }

        $enCadena = $actor->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($actor->colaborador, $colaborador);

        // «Iniciar baja sin permiso» = sin solicitud previa, NO que cualquiera
        // pueda dar de baja a cualquiera: el jefe directo real (cadena de
        // mando) o RH con alcance sobre la persona.
        if ($actor->colaborador_id === $colaborador->id
            || ($esRh && ! $enCadena && ! $this->alcance->alcanzaColaborador($actor, $colaborador))
            || (! $esRh && ! $enCadena)) {
            throw new AuthorizationException('Solo el jefe directo de la persona o RH pueden iniciar su baja.');
        }

        $tipoBaja = TipoBaja::from((string) $datos['tipo_baja']);

        if (! $esRh && ! in_array($tipoBaja->value, (array) config('ciclo_laboral.cierre.causas_solicitables', []), true)) {
            throw ValidationException::withMessages(['tipo_baja' => 'Elige una causa válida: renuncia, no renovación, bajo desempeño o baja inmediata.']);
        }

        $this->exigirSinCierreAbierto($colaborador);

        $motivo = trim((string) $datos['motivo']);
        $fechaEfectiva = (string) $datos['fecha_efectiva'];
        $observaciones = isset($datos['observaciones']) ? (string) $datos['observaciones'] : null;

        // Quién aplicó la baja (timeline/aviso): el jefe directo en la cadena
        // de mando del organigrama, o Recursos Humanos.
        $aplicadaPor = $enCadena ? 'jefe directo' : 'Recursos Humanos';

        $cierre = DB::transaction(function () use ($colaborador, $actor, $tipoBaja, $motivo, $fechaEfectiva, $observaciones, $aplicadaPor): CierreLaboral {
            Colaborador::query()->lockForUpdate()->findOrFail($colaborador->id);
            $this->exigirSinCierreAbierto($colaborador);

            // La solicitud de baja existente es el "expediente del trámite":
            // folio, historial y finiquito.
            $solicitud = $this->solicitudes->crear($actor, [
                'tipo' => TipoSolicitudInterna::BajaColaborador->value,
                'colaborador_objetivo_id' => $colaborador->id,
                'tipo_baja' => $tipoBaja->value,
                'fecha_efectiva' => $fechaEfectiva,
                'motivo' => $motivo,
                'observaciones' => $observaciones,
            ]);

            // Baja inmediata: sin preautorización ni autorización de RH.
            $cierre = CierreLaboral::query()->create([
                'colaborador_id' => $colaborador->id,
                'solicitud_interna_id' => $solicitud->id,
                'tipo_baja' => $tipoBaja,
                'motivo' => $motivo,
                'fecha_efectiva' => $fechaEfectiva,
                'estado' => EstadoCierreLaboral::Iniciado,
                'iniciado_por' => $actor->id,
                'autorizado_rh_en' => now(),
                'autorizado_rh_por' => $actor->id,
                'observaciones' => $observaciones,
            ]);

            $this->solicitudes->marcarEnRevision($solicitud, $actor, sprintf('Baja aplicada por %s.', $aplicadaPor), desdeCierreLaboral: true);

            // Baja aplicada = accesos fuera desde ya (web y app).
            $cuenta = $colaborador->user;

            if ($cuenta !== null && $cuenta->acceso_bloqueado_en === null) {
                $this->acceso->revocar($cuenta, $actor, sprintf('%s %s', self::MOTIVO_SUSPENSION, $tipoBaja->etiqueta()));
                $cierre->update(['acceso_suspendido_en' => now()]);
            }

            return $cierre;
        });

        $nombre = $colaborador->nombreCompleto();
        $this->notificador->notificarEvento(
            'cierre_baja_solicitada',
            $colaborador,
            ['solicitante' => $actor, 'excluir' => [$actor->id]],
            'Baja aplicada',
            sprintf('%s aplicó la baja de %s (%s, efectiva %s). Sus accesos ya se suspendieron; puedes rehabilitarlos desde Usuarios.', $actor->name, $nombre, $tipoBaja->etiqueta(), $cierre->fecha_efectiva->format('d/m/Y')),
            $cierre,
            'ver_cierre',
            'alta',
        );

        $this->auditoria->registrar('cierre_laboral_aplicado', $cierre, $actor, [
            'colaborador_id' => $colaborador->id,
            'tipo_baja' => $tipoBaja->value,
            'fecha_efectiva' => $cierre->fecha_efectiva->toDateString(),
            'aplicada_por' => $aplicadaPor,
        ]);

        // Documentos de la causa (la renuncia incluida): quedan generados en
        // el mismo momento con nombre, puesto, sucursal y fecha ya llenos —
        // solo falta imprimirlos y firmarlos. Si falta el master o algún
        // dato, queda un pendiente claro (generarDocumentosDeCausa) y la baja
        // sigue su curso.
        try {
            $this->generarDocumentosDeCausa($cierre->refresh(), $actor, $this->documentosProceso->clavesCausa($cierre));
        } catch (Throwable $e) {
            Log::warning('CierreLaboralService: no se pudieron preparar los documentos de la baja.', ['cierre_id' => $cierre->id, 'error' => $e->getMessage()]);
        }

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

            $this->solicitudes->marcarEnRevision($solicitud, $actor, 'No renovación autorizada por RH en la evaluación del periodo de prueba.', desdeCierreLaboral: true);

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

        $this->generarDocumentosDeCausa($cierre, $actor, $this->documentosProceso->clavesCausa($cierre));
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    /**
     * Registra un aviso firmado externo para una causa que NO tiene documento
     * del sistema. La renuncia y el aviso de terminación los genera el
     * sistema: su firma física se registra en «Documentos de la baja», nunca
     * subiendo un archivo.
     */
    public function registrarAviso(CierreLaboral $cierre, UploadedFile $archivo, User $actor): CierreLaboral
    {
        $this->exigirAutorizado($cierre);

        if ($this->documentosProceso->clavesCausa($cierre) !== []) {
            throw ValidationException::withMessages(['archivo' => 'Este documento lo genera el sistema: imprímelo y marca su firma física en «Documentos de la baja». No se sube archivo.']);
        }
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

        // Vencimiento de capacitación: el aviso oficial de Jurídico (master).
        if (in_array('aviso_terminacion', $this->documentosProceso->clavesCausa($cierre), true)) {
            return $this->documentosProceso->documentoVigente($cierre, 'aviso_terminacion')
                ?? $this->documentosProceso->generar('baja', $cierre, 'aviso_terminacion', $actor);
        }

        return $this->motor->generar($cierre->colaborador, 'aviso_termino', $actor, $this->variables($cierre), $cierre);
    }

    /**
     * Calcula (o recalcula) el finiquito en borrador. Sin sueldo capturado
     * se usa el del cálculo anterior o el salario diario registrado del
     * colaborador × 30. Los ajustes y conceptos que RH ya capturó se
     * conservan. Editable hasta «Registrar entrega de finiquito».
     */
    public function calcularFiniquito(CierreLaboral $cierre, User $actor, ?float $sueldoMensual = null, float $sueldoPendiente = 0): FiniquitoCalculo
    {
        $this->exigirNoFinal($cierre);
        $this->exigirAutorizado($cierre);

        $solicitud = $this->solicitud($cierre);
        $existente = FiniquitoCalculo::query()->where('solicitud_interna_id', $solicitud->id)->first();

        if ($existente !== null && in_array($existente->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
            throw ValidationException::withMessages(['finiquito' => 'El finiquito ya se entregó: es de solo lectura.']);
        }

        $sueldoMensual ??= $existente !== null && (float) $existente->sueldo_mensual > 0
            ? (float) $existente->sueldo_mensual
            : $this->finiquitos->sueldoMensualSugerido($cierre->colaborador);

        if ($sueldoMensual <= 0) {
            throw ValidationException::withMessages(['sueldo_mensual' => 'El colaborador no tiene salario registrado: captura el sueldo mensual para calcular el finiquito.']);
        }

        $finiquito = $existente === null
            ? $this->finiquitos->calcular($solicitud, $actor, $sueldoMensual, $sueldoPendiente)
            : $this->finiquitos->recalcular($existente, $actor, $sueldoMensual, $sueldoPendiente);

        $cierre->update(['estado' => $this->avanzar($cierre, EstadoCierreLaboral::FiniquitoEnProceso)]);
        $this->auditoria->registrar('finiquito_calculado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'neto' => $finiquito->neto]);
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $finiquito;
    }

    /**
     * Último paso del cierre: RH registra que ENTREGÓ el finiquito (fecha,
     * forma de pago, comentario). En una sola operación congela el
     * finiquito (snapshot, solo lectura), ejecuta la baja y cierra el
     * expediente: el cierre queda «Cierre laboral completo» (100%). No hay
     * autorización del finiquito: RH lo calcula/ajusta y lo entrega.
     * Sirve también para cierres que venían del flujo anterior (finiquito
     * autorizado, pago programado, firmado), para que ninguno se quede
     * atorado.
     *
     * @param  array{fecha: string, forma_pago?: string|null, comentario?: string|null}  $datos
     */
    public function registrarEntregaFiniquito(CierreLaboral $cierre, User $actor, array $datos): CierreLaboral
    {
        $this->exigirNoFinal($cierre);
        $this->exigirAutorizado($cierre);
        $this->exigirEstado($cierre, [
            EstadoCierreLaboral::Iniciado, EstadoCierreLaboral::AvisoRegistrado, EstadoCierreLaboral::FiniquitoEnProceso,
            EstadoCierreLaboral::FiniquitoAutorizado, EstadoCierreLaboral::PagoProgramado, EstadoCierreLaboral::FiniquitoFirmado,
            EstadoCierreLaboral::Pagado, EstadoCierreLaboral::BajaEjecutada,
        ]);

        if ($cierre->fecha_efectiva->isAfter(now()->startOfDay())) {
            throw ValidationException::withMessages(['fecha' => sprintf('La baja es efectiva el %s: registra la entrega del finiquito a partir de esa fecha.', $cierre->fecha_efectiva->format('d/m/Y'))]);
        }

        $finiquito = $this->exigirFiniquito($cierre);
        $fecha = Carbon::parse($datos['fecha'])->startOfDay();
        $formaPago = $datos['forma_pago'] ?? null;
        $comentario = $datos['comentario'] ?? null;

        // Checklist del procedimiento integral (no renovación / fin de
        // contrato) ANTES de tocar nada: el punto del finiquito es justo lo
        // que esta entrega cumple; cualquier otro faltante detiene el cierre.
        $faltantes = $this->documentosProceso->checklistFaltantes($cierre, ['finiquito']);

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['checklist' => sprintf('Antes de cerrar falta: %s. Revisa «Documentos de la baja».', implode(', ', $faltantes))]);
        }

        $cierre = DB::transaction(function () use ($cierre, $actor, $finiquito, $fecha, $formaPago, $comentario): CierreLaboral {
            $cierre = $this->bloquear($cierre, [
                EstadoCierreLaboral::Iniciado, EstadoCierreLaboral::AvisoRegistrado, EstadoCierreLaboral::FiniquitoEnProceso,
                EstadoCierreLaboral::FiniquitoAutorizado, EstadoCierreLaboral::PagoProgramado, EstadoCierreLaboral::FiniquitoFirmado,
                EstadoCierreLaboral::Pagado, EstadoCierreLaboral::BajaEjecutada,
            ]);
            $finiquito = $this->finiquitos->registrarEntrega($finiquito, $actor, $fecha, $formaPago, $comentario);

            $cierre->update([
                'pago_monto' => $finiquito->neto,
                'pago_metodo' => $formaPago ?? $cierre->pago_metodo,
                'pago_observaciones' => $comentario ?? $cierre->pago_observaciones,
                'pago_confirmado_en' => $cierre->pago_confirmado_en ?? $fecha,
                'pago_confirmado_por' => $cierre->pago_confirmado_por ?? $actor->id,
            ]);

            $this->auditoria->registrar('finiquito_entregado', $cierre, $actor, [
                'colaborador_id' => $cierre->colaborador_id,
                'neto' => $finiquito->neto,
                'fecha' => $fecha->toDateString(),
                'forma_pago' => $formaPago,
                'comentario' => $comentario,
            ]);

            if ($cierre->estado !== EstadoCierreLaboral::BajaEjecutada) {
                $cierre = $this->ejecutarBaja($cierre->refresh(), $actor);
            }

            return $this->cerrarExpediente($cierre, $actor);
        });

        return $cierre->refresh();
    }

    /**
     * LEGACY: ya no tiene ruta ni acción (RH no autoriza el finiquito: lo
     * calcula y registra su entrega con registrarEntregaFiniquito()). Se
     * conserva solo para reproducir cierres del flujo anterior en datos de
     * demostración y pruebas.
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

        // Procedimiento integral de baja, escenario B: si el colaborador se
        // negó a firmar/recibir, el finiquito queda a su disposición (y en su
        // caso se consigna); la baja operativa no espera su firma ni su pago.
        $aDisposicion = $cierre->negativa_firma_en !== null && $cierre->finiquito_a_disposicion;

        if (! $aDisposicion && config('contratos.cierre.requiere_finiquito_firmado') && ! in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
            throw ValidationException::withMessages(['finiquito' => 'El finiquito debe estar firmado antes de ejecutar la baja.']);
        }

        if (! $aDisposicion && config('contratos.cierre.requiere_pago_confirmado') && $finiquito->pagado_en === null) {
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
                $this->solicitudes->aprobar($solicitud, $actor, 'Baja ejecutada desde cierre laboral.', desdeCierreLaboral: true);
            }

            ContratoLaboral::query()
                ->where('colaborador_id', $cierre->colaborador_id)
                ->where('estado', EstadoContratoLaboral::Vigente->value)
                ->update(['estado' => EstadoContratoLaboral::Terminado->value]);

            $cierre->colaborador->forceFill(['fecha_baja' => $cierre->fecha_efectiva->toDateString()])->save();

            $cierre->update(['baja_ejecutada_en' => now(), 'estado' => EstadoCierreLaboral::BajaEjecutada, 'accesos_cancelados_en' => $cierre->accesos_cancelados_en ?? now()]);
        });

        $this->auditoria->registrar('cierre_laboral_baja', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id]);

        return $cierre->refresh();
    }

    public function cerrarExpediente(CierreLaboral $cierre, User $actor): CierreLaboral
    {
        if ($cierre->estado !== EstadoCierreLaboral::BajaEjecutada) {
            throw ValidationException::withMessages(['estado' => 'El expediente se cierra después de ejecutar la baja.']);
        }

        // Checklist final del procedimiento integral de baja (vencimiento de
        // capacitación): no se cierra sin los documentos que apliquen.
        if (! $this->documentosProceso->checklistCompleto($cierre)) {
            throw ValidationException::withMessages(['checklist' => 'Faltan puntos del checklist final del procedimiento de baja (documentos firmados o acta de negativa, evidencia de notificación electrónica, finiquito pagado o consignado, baja IMSS). Revisa «Documentos de la baja».']);
        }

        DB::transaction(function () use ($cierre, $actor): void {
            $cierre->colaborador->forceFill([
                'expediente_cerrado_en' => now(),
                'expediente_cerrado_por' => $actor->id,
            ])->save();

            $solicitud = $this->solicitud($cierre);

            // La solicitud de baja ya quedó Aprobada (definitiva); el cierre
            // del expediente solo se anota en su historial.
            $this->solicitudes->registrarHistorial($solicitud, $actor, 'comentario', 'Expediente cerrado.');

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
                $this->solicitudes->cancelar($solicitud, $actor, desdeCierreLaboral: true);
            }

            $this->aprobaciones->cancelarPendientes($cierre, ProcesoAprobacion::CierreLaboral, $actor, $motivo);
            $cierre->update(['estado' => EstadoCierreLaboral::Cancelado, 'observaciones' => trim(($cierre->observaciones ?? '')."\nCancelado: {$motivo}")]);
        });

        $this->auditoria->registrar('cierre_laboral_cancelado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'motivo' => $motivo]);
        $this->devolverAcceso($cierre, $actor, 'Baja cancelada');
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
        // Alcance del módulo «cierres» (Roles y permisos), no por nombre de rol.
        $this->alcance->limitarPorModulo($query, $usuario, 'cierres', 'colaborador_id', true);

        $estado = isset($filtros['estado']) ? (string) $filtros['estado'] : null;

        return $query
            ->when($estado === 'abiertos', fn (Builder $q) => $q->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos())))
            ->when($estado !== null && $estado !== 'abiertos', fn (Builder $q) => $q->where('estado', $estado))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('colaborador_id', (int) $v))
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null));
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
        $rh = $usuario->can(self::PERMISO_GESTIONAR);

        // La baja se aplica al iniciarla: no hay acciones de preautorizar,
        // autorizar, devolver ni reenviar (Solicitado/PendienteRh solo
        // existen en el historial anterior a ese cambio).
        switch ($cierre->estado) {
            case EstadoCierreLaboral::Iniciado:
            case EstadoCierreLaboral::AvisoRegistrado:
            case EstadoCierreLaboral::FiniquitoEnProceso:
            case EstadoCierreLaboral::FiniquitoAutorizado:
            case EstadoCierreLaboral::PagoProgramado:
            case EstadoCierreLaboral::FiniquitoFirmado:
                // Nada se sube: la renuncia / el aviso de terminación los
                // genera el sistema y su firma se registra en «Documentos de
                // la baja»; las causas sin documento del sistema pasan directo
                // al finiquito. RH lo calcula/ajusta y registra su entrega,
                // que cierra el cierre (también los que venían del flujo
                // anterior con finiquito autorizado / pago programado /
                // firmado): no hay autorizar, programar pago ni cita.
                $acciones = [...$acciones, ...$this->accionesFiniquito($cierre, $usuario)];
                break;
            case EstadoCierreLaboral::Pagado:
            case EstadoCierreLaboral::BajaEjecutada:
                if ($rh) {
                    $acciones[] = ['clave' => 'cerrar', 'etiqueta' => 'Concluir cierre laboral', 'tipo' => 'primaria'];
                }
                $acciones = [...$acciones, ...array_values(array_filter($this->accionesFiniquito($cierre, $usuario), fn (array $a): bool => $a['clave'] === 'ver_finiquito'))];
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
     * Finiquito: RH lo calcula, lo revisa/edita por concepto y registra su
     * entrega (que cierra el cierre). No hay paso de «autorizar finiquito».
     *
     * @return list<array{clave: string, etiqueta: string, tipo: string}>
     */
    private function accionesFiniquito(CierreLaboral $cierre, User $usuario): array
    {
        if (! $usuario->can('finiquitos.calcular')) {
            return [];
        }

        $finiquito = $this->finiquito($cierre);

        if ($finiquito === null) {
            return [['clave' => 'calcular_finiquito', 'etiqueta' => 'Calcular finiquito', 'tipo' => 'primaria']];
        }

        $acciones = [];

        if ($usuario->can(self::PERMISO_GESTIONAR) && ! in_array($finiquito->estado, [EstadoFiniquito::Pagado], true)) {
            $acciones[] = ['clave' => 'registrar_entrega_finiquito', 'etiqueta' => 'Registrar entrega de finiquito', 'tipo' => 'primaria'];
        }

        $acciones[] = ['clave' => 'ver_finiquito', 'etiqueta' => 'Ver / editar finiquito', 'tipo' => 'secundaria'];

        if (! in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
            $acciones[] = ['clave' => 'calcular_finiquito', 'etiqueta' => 'Recalcular finiquito', 'tipo' => 'secundaria'];
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
            $finiquito->loadMissing('calculadoPor');
            $entregado = in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true);
            $snapshot = is_array($finiquito->snapshot) ? $finiquito->snapshot : [];
            $entrega = isset($snapshot['entrega']) && is_array($snapshot['entrega']) ? $snapshot['entrega'] : null;
            $formaPago = $entrega !== null && is_string($entrega['forma_pago'] ?? null) ? FormaPagoFiniquito::tryFrom($entrega['forma_pago']) : null;

            // Entregado: se muestra el desglose CONGELADO en la entrega.
            $datosFiniquito['desglose'] = $entregado && $entrega !== null && isset($snapshot['conceptos']) && is_array($snapshot['conceptos'])
                ? $snapshot['conceptos']
                : $this->finiquitos->desglose($finiquito);
            $datosFiniquito += [
                'estado_etiqueta' => $entregado ? 'Entregado' : 'Calculado · editable hasta la entrega',
                'editable' => ! $entregado && $viewer?->can('finiquitos.calcular') === true,
                'fecha_calculo' => $finiquito->fecha_calculo->toIso8601String(),
                'calculado_por' => $finiquito->calculadoPor?->nombreCompleto(),
                'fecha_ingreso' => $finiquito->fecha_ingreso->toDateString(),
                'fecha_baja' => $finiquito->fecha_baja->toDateString(),
                'sueldo_mensual' => $finiquito->sueldo_mensual,
                'sueldo_diario' => $finiquito->sueldo_diario,
                'antiguedad_anios' => $finiquito->antiguedad_anios,
                'antiguedad_meses' => $finiquito->antiguedad_meses,
                'dias_trabajados_periodo' => $finiquito->dias_trabajados_periodo,
                'dias_trabajados_anio' => $finiquito->dias_trabajados_anio,
                'vacaciones_pendientes' => $finiquito->vacaciones_pendientes,
                'vacaciones_proporcionales' => (float) $finiquito->vacaciones_proporcionales,
                'sueldo_pendiente' => $finiquito->sueldo_pendiente,
                'ultima_edicion' => $this->finiquitos->ultimaEdicion($finiquito),
                'entrega' => $entrega !== null ? [
                    'fecha' => $entrega['fecha'] ?? null,
                    'forma_pago' => $formaPago?->etiqueta(),
                    'comentario' => $entrega['comentario'] ?? null,
                ] : null,
            ];
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
            'sueldo_mensual_sugerido' => $detalle ? $this->finiquitos->sueldoMensualSugerido($cierre->colaborador) : null,
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
                $this->documentosProceso->generar('baja', $cierre, $clave, $actor);
            } catch (ValidationException|AuthorizationException $e) {
                $this->tareas->abrir(TipoTarea::PlantillaFaltante, $cierre, [
                    'titulo' => sprintf($e instanceof DatosDocumentoFaltantesException ? 'Faltan datos para «%s» del cierre de %s' : 'Documento «%s» pendiente para el cierre de %s', $this->documentosProceso->nombreDocumento($clave), $cierre->colaborador->nombreCompleto()),
                    'descripcion' => $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage(),
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $cierre->colaborador,
                    'permiso' => 'plantillas_documentales.administrar',
                    'accion' => 'cargar_plantilla',
                    // motivo: lo que debe dejar de faltar para que el pendiente se resuelva solo.
                    'datos' => ['clave' => $clave, 'motivo' => $e instanceof AuthorizationException ? 'permiso' : 'datos'],
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

        // CierrePreautorizacion/CierreAutorizacionRh solo se resuelven (arriba):
        // la baja ya no espera preautorización ni autorización de RH.
        switch ($cierre->estado) {
            case EstadoCierreLaboral::Iniciado:
            case EstadoCierreLaboral::AvisoRegistrado:
                $this->tareas->abrir(TipoTarea::FiniquitoPendiente, $cierre, [...$base,
                    'titulo' => "Finiquito pendiente: {$nombre}",
                    'permiso' => 'finiquitos.calcular',
                    'accion' => 'calcular_finiquito',
                ]);
                break;
            case EstadoCierreLaboral::FiniquitoEnProceso:
            case EstadoCierreLaboral::FiniquitoAutorizado:
            case EstadoCierreLaboral::PagoProgramado:
            case EstadoCierreLaboral::FiniquitoFirmado:
            case EstadoCierreLaboral::Pagado:
                // Sin autorización del finiquito, pago programado ni cita:
                // RH lo revisa/ajusta y registra su entrega, que cierra el
                // cierre laboral. Los cierres que venían del flujo anterior
                // (autorizado/pago programado/firmado/pagado) quedan con el
                // mismo único pendiente para que ninguno se quede atorado.
                $this->tareas->abrir(TipoTarea::FiniquitoPorAutorizar, $cierre, [...$base,
                    'titulo' => "Entregar finiquito: {$nombre}",
                    'permiso' => self::PERMISO_GESTIONAR,
                    'accion' => 'registrar_entrega_finiquito',
                ]);
                break;
            default:
                break;
        }
    }

    /**
     * Si la baja no procede, la persona recupera el acceso — solo cuando la
     * suspensión la puso este trámite (si alguien la bloqueó por otra razón,
     * se respeta).
     */
    private function devolverAcceso(CierreLaboral $cierre, User $actor, string $motivo): void
    {
        $cuenta = $cierre->colaborador->user;

        if ($cierre->acceso_suspendido_en === null || $cuenta === null || ! str_starts_with((string) $cuenta->acceso_bloqueado_motivo, self::MOTIVO_SUSPENSION)) {
            return;
        }

        $this->acceso->restablecer($cuenta, $actor, $motivo);
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
            throw ValidationException::withMessages(['estado' => 'Esta baja no está aplicada: esta acción no aplica.']);
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
