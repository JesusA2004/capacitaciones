<?php

namespace App\Services\CierreLaboral;

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\FormaPagoFiniquito;
use App\Enums\FormaSalida;
use App\Enums\PrioridadTarea;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoBaja;
use App\Enums\TipoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Exceptions\DatosDocumentoFaltantesException;
use App\Models\Baja;
use App\Models\CausalRotacion;
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
use App\Services\Bajas\BajaAlertasService;
use App\Services\Bajas\BajaRegistroService;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use App\Support\FechaMexico;
use App\Support\Paginacion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
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
 *   3. finiquito: RH calcula / revisa / ajusta por concepto (auditado) y
 *      REGISTRA SU ENTREGA (registrarEntregaFiniquito): la entrega congela el
 *      finiquito, ejecuta la baja (solo desde la fecha efectiva) y cierra el
 *      expediente en una sola operación. No hay autorización del finiquito,
 *      programación del pago ni cita: ese flujo anterior quedó apagado
 *      (features.cierre_flujo_legacy, solo para expedientes legacy).
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
        private readonly CausalRotacionService $causales,
        private readonly BajaRegistroService $bajas,
        private readonly BajaAlertasService $alertasBaja,
        private readonly FotoColaboradorService $fotos,
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
        // Gerente con alcance sobre la persona en el módulo de Bajas (su
        // personal / sucursal / región), igual que BajaService::registrar().
        $porAlcance = $actor->can('bajas.solicitar') && $this->alcance->alcanzaColaboradorEnModulo($actor, $colaborador, 'bajas');

        if (! $esRh && ! $actor->can(self::PERMISO_SOLICITAR) && ! $porAlcance) {
            throw new AuthorizationException('No tienes permiso para registrar bajas.');
        }

        $enCadena = $actor->colaborador !== null && $actor->can(self::PERMISO_SOLICITAR) && $this->jerarquia->estaEnCadenaDeMando($actor->colaborador, $colaborador);

        // «Iniciar baja sin permiso» = sin solicitud previa, NO que cualquiera
        // pueda dar de baja a cualquiera: el gerente / jefe en la cadena de
        // mando, el gerente con alcance sobre la persona o RH con alcance.
        if ($actor->colaborador_id === $colaborador->id
            || ($esRh && ! $enCadena && ! $this->alcance->alcanzaColaborador($actor, $colaborador))
            || (! $esRh && ! $enCadena && ! $porAlcance)) {
            throw new AuthorizationException('Solo el gerente de la persona o RH pueden registrar su baja.');
        }

        // Solo tres tipos (renuncia, rescisión, fin de contrato) + su causal
        // de rotación (subcatálogo que RH administra en Bajas y finiquitos).
        $tipoBaja = TipoBaja::from((string) $datos['tipo_baja']);

        if (! in_array($tipoBaja, $this->causales->tiposPermitidos($actor), true)) {
            throw ValidationException::withMessages(['tipo_baja' => sprintf('No puedes iniciar una baja por %s: pídela a RH.', mb_strtolower($tipoBaja->etiqueta()))]);
        }

        // Atajo (RH / jefe directo, web y API móvil): la causal se valida si
        // viene (activa y de la forma); si no viene —APKs instaladas que no la
        // mandan— la baja queda «causal pendiente» y RH no puede validarla ni
        // cerrarla sin asignarla (RN-01, BajaService).
        $causal = $this->causales->resolver($tipoBaja, $datos['causal_rotacion_id'] ?? null, obligatoria: false);

        $this->exigirSinCierreAbierto($colaborador);
        $this->bajas->exigirSinBajaAbierta($colaborador);

        // El texto libre (observaciones / detalle) es opcional: sin él, el
        // motivo registrado es la causal elegida.
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        $motivo = $motivo !== '' ? $motivo : ($causal->nombre ?? $tipoBaja->etiqueta());

        // Quién aplicó la baja (timeline/aviso): el jefe directo en la cadena
        // de mando del organigrama, o Recursos Humanos.
        $aplicadaPor = match (true) {
            $enCadena => 'jefe directo',
            $esRh => 'Recursos Humanos',
            default => 'gerente',
        };

        return $this->aplicar($colaborador, $tipoBaja, $causal, $motivo, (string) $datos['fecha_efectiva'], isset($datos['observaciones']) ? (string) $datos['observaciones'] : null, $actor, $aplicadaPor, null, $datos);
    }

    /**
     * La baja que el gerente ENVIÓ a RH (módulo de Bajas) se aplica igual que
     * el atajo: solicitud + cierre «Baja aplicada · documentos pendientes»,
     * accesos suspendidos desde ya y documentos de la causa generados. La
     * autorización (permiso + alcance) y las reglas RN-01…RN-10 ya las
     * validó BajaService.
     */
    public function aplicarDesdeBaja(Baja $baja, User $actor): CierreLaboral
    {
        $colaborador = $baja->colaborador;
        $forma = $baja->forma_salida;

        if (! $colaborador instanceof Colaborador || $forma === null || $baja->fecha_baja === null) {
            throw ValidationException::withMessages(['baja' => 'La baja no tiene colaborador, forma de salida o fecha de baja.']);
        }

        $this->exigirSinCierreAbierto($colaborador);
        $causal = $baja->causal;
        $motivo = trim((string) $baja->comentarios);

        $aplicadaPor = $actor->can(self::PERMISO_GESTIONAR) ? 'Recursos Humanos' : 'gerente';

        return $this->aplicar($colaborador, $forma->tipoBaja(), $causal, $motivo !== '' ? $motivo : ($causal->nombre ?? $forma->etiqueta()), $baja->fecha_baja->toDateString(), null, $actor, $aplicadaPor, $baja, []);
    }

    /**
     * Núcleo común del atajo y de la baja enviada a RH.
     *
     * @param  array<string, mixed>  $datos  Datos del atajo para el expediente de la baja (recontratable, rescisión…).
     */
    private function aplicar(Colaborador $colaborador, TipoBaja $tipoBaja, ?CausalRotacion $causal, string $motivo, string $fechaEfectiva, ?string $observaciones, User $actor, string $aplicadaPor, ?Baja $baja, array $datos): CierreLaboral
    {
        $cierre = DB::transaction(function () use ($colaborador, $actor, $tipoBaja, $causal, $motivo, $fechaEfectiva, $observaciones, $aplicadaPor, $baja, $datos): CierreLaboral {
            Colaborador::query()->lockForUpdate()->findOrFail($colaborador->id);
            $this->exigirSinCierreAbierto($colaborador);

            // La solicitud de baja existente es el "expediente del trámite":
            // folio, historial y finiquito.
            $solicitud = $this->solicitudes->crear($actor, [
                'tipo' => TipoSolicitudInterna::BajaColaborador->value,
                'colaborador_objetivo_id' => $colaborador->id,
                'tipo_baja' => $tipoBaja->value,
                'causal_rotacion_id' => $causal?->id,
                'fecha_efectiva' => $fechaEfectiva,
                'motivo' => $motivo,
                'observaciones' => $observaciones,
            ]);

            // Baja inmediata: sin preautorización ni autorización de RH.
            $cierre = $this->nuevoCierre($colaborador, $tipoBaja, $motivo, $fechaEfectiva, $actor, $solicitud->id, null, $observaciones, $causal?->id);
            $cierre->save();

            $this->solicitudes->marcarEnRevision($solicitud, $actor, sprintf('Baja aplicada por %s.', $aplicadaPor), desdeCierreLaboral: true);

            // Baja aplicada = accesos fuera desde ya (web y app).
            $cuenta = $colaborador->user;

            if ($cuenta !== null && $cuenta->acceso_bloqueado_en === null) {
                $this->acceso->revocar($cuenta, $actor, sprintf('%s %s', self::MOTIVO_SUSPENSION, $tipoBaja->etiqueta()));
                $cierre->update(['acceso_suspendido_en' => now()]);
            }

            // Expediente de la baja (módulo de Bajas): la del gerente se liga
            // a su cierre; el atajo crea la suya ya «En revisión RH».
            $this->bajas->ligarCierre($cierre, $actor, $baja, $datos, $aplicadaPor);

            return $cierre;
        });

        // «Baja registrada» por la capa única (RH, regionales y Sistemas):
        // quién la dio, a quién, forma · causal y fecha. Un fallo al
        // notificar nunca deshace la baja.
        try {
            $this->notificador->notificarEvento(
                'cierre_baja_solicitada',
                $colaborador,
                ['solicitante' => $actor, 'excluir' => [$actor->id]],
                sprintf('Baja registrada: %s', $colaborador->nombreCompleto()),
                sprintf(
                    '%s registró la baja de %s (%s%s) por %s%s, último día %s. Sus accesos ya se suspendieron; RH puede rehabilitarlos desde Usuarios.',
                    trim(sprintf('%s %s', $actor->name, $actor->apellidos)),
                    $colaborador->nombreCompleto(),
                    $colaborador->puesto->nombre ?? 'sin puesto',
                    $colaborador->sucursalPrincipal !== null ? ' · '.$colaborador->sucursalPrincipal->nombre : '',
                    mb_strtolower(FormaSalida::desdeTipoBaja($tipoBaja)->etiqueta()),
                    $causal !== null ? sprintf(' · %s', $causal->etiquetaConClave()) : '',
                    $cierre->fecha_efectiva->format('d/m/Y'),
                ),
                $cierre,
                'ver_cierre',
                'alta',
            );
        } catch (Throwable $e) {
            Log::warning('CierreLaboralService: no se pudo avisar la baja registrada.', ['cierre_id' => $cierre->id, 'error' => $e->getMessage()]);
        }

        $this->auditoria->registrar('cierre_laboral_aplicado', $cierre, $actor, [
            'colaborador_id' => $colaborador->id,
            'tipo_baja' => $tipoBaja->value,
            'causal_rotacion' => $causal?->nombre,
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

        // Atajo (sin baja del módulo): la baja nace «En trámite» con sus
        // alertas. La del módulo de Bajas la avisa BajaService.
        $registrada = $baja === null ? $cierre->baja()->first() : null;

        if ($registrada !== null) {
            $this->alertasBaja->alRegistrar($registrada, $actor);
        }

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
     * No renovación decidida en la evaluación del periodo de prueba (el jefe
     * la recomienda y RH valida la EVALUACIÓN; eso es del periodo de prueba,
     * no una autorización de la baja). El cierre nace aplicado como cualquier
     * baja — sin preautorización ni autorización posterior de RH — y sigue
     * documentos → finiquito → registrar entrega → cierre.
     *
     * @param  array<string, mixed>  $datos
     */
    public function iniciarDesdeEvaluacion(Colaborador $colaborador, array $datos, User $actor, EvaluacionPeriodoPrueba $evaluacion): CierreLaboral
    {
        $this->exigirSinCierreAbierto($colaborador);
        $this->bajas->exigirSinBajaAbierta($colaborador);
        $tipoBaja = TipoBaja::from((string) $datos['tipo_baja']);
        $motivo = (string) $datos['motivo'];
        $fechaEfectiva = (string) $datos['fecha_efectiva'];
        // Causal automática: «No acredita periodo de prueba» (si RH la quitó
        // del catálogo, la baja queda sin causal y sigue su curso).
        $causal = isset($datos['causal_rotacion_id'])
            ? $this->causales->resolver($tipoBaja, $datos['causal_rotacion_id'])
            : $this->causales->porClave((string) config('bajas.clave_no_renovacion'), $tipoBaja);

        $cierre = DB::transaction(function () use ($colaborador, $actor, $evaluacion, $tipoBaja, $causal, $motivo, $fechaEfectiva): CierreLaboral {
            $solicitud = $this->solicitudes->crear($actor, [
                'tipo' => TipoSolicitudInterna::BajaColaborador->value,
                'colaborador_objetivo_id' => $colaborador->id,
                'tipo_baja' => $tipoBaja->value,
                'causal_rotacion_id' => $causal?->id,
                'fecha_efectiva' => $fechaEfectiva,
                'motivo' => $motivo,
            ]);

            $this->solicitudes->marcarEnRevision($solicitud, $actor, 'No renovación autorizada por RH en la evaluación del periodo de prueba.', desdeCierreLaboral: true);

            $cierre = $this->nuevoCierre($colaborador, $tipoBaja, $motivo, $fechaEfectiva, $actor, $solicitud->id, $evaluacion->id, null, $causal?->id);
            $cierre->save();

            // Expediente de la baja (NR-02 por defecto), ya «En revisión RH».
            $this->bajas->ligarCierre($cierre, $actor, null, [], 'Recursos Humanos (no renovación del periodo de prueba)');

            return $cierre;
        });

        $this->auditoria->registrar('cierre_laboral_aplicado', $cierre, $actor, [
            'colaborador_id' => $colaborador->id,
            'tipo_baja' => $tipoBaja->value,
            'fecha_efectiva' => $cierre->fecha_efectiva->toDateString(),
            'aplicada_por' => 'Recursos Humanos (no renovación del periodo de prueba)',
            'evaluacion_id' => $evaluacion->id,
        ]);

        $this->generarDocumentosDeCausa($cierre, $actor, $this->documentosProceso->clavesCausa($cierre));
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        $registrada = $cierre->baja()->first();

        if ($registrada !== null) {
            $this->alertasBaja->alRegistrar($registrada, $actor);
        }

        return $cierre;
    }

    /**
     * Cierre aplicado SIN guardar (mismos datos con los que solicitar() e
     * iniciarDesdeEvaluacion() lo crean): baja inmediata, sin
     * preautorización ni autorización posterior de RH.
     */
    private function nuevoCierre(Colaborador $colaborador, TipoBaja $tipoBaja, string $motivo, string $fechaEfectiva, User $actor, ?int $solicitudId, ?int $evaluacionId = null, ?string $observaciones = null, ?int $causalId = null): CierreLaboral
    {
        return CierreLaboral::query()->make([
            'colaborador_id' => $colaborador->id,
            'solicitud_interna_id' => $solicitudId,
            'evaluacion_id' => $evaluacionId,
            'tipo_baja' => $tipoBaja,
            'causal_rotacion_id' => $causalId,
            'motivo' => $motivo,
            'fecha_efectiva' => $fechaEfectiva,
            'estado' => EstadoCierreLaboral::Iniciado,
            'iniciado_por' => $actor->id,
            'autorizado_rh_en' => now(),
            'autorizado_rh_por' => $actor->id,
            'observaciones' => $observaciones,
        ]);
    }

    /**
     * Cierre que nacería al confirmar la baja (o la no renovación de una
     * evaluación) con estos datos, EN MEMORIA: para ver la renuncia, el aviso
     * de terminación o el formato de evaluación ANTES de confirmar. No guarda
     * nada (ni solicitud, ni cierre, ni suspensión de acceso).
     *
     * Con evaluación (no renovación desde el periodo de prueba): la
     * evaluación se ve como quedará al autorizarla (validada por RH, sin
     * renovar), igual que la verá la generación real.
     *
     * @param  array<string, mixed>  $datos  tipo_baja, motivo?, fecha_efectiva?
     */
    public function propuesta(Colaborador $colaborador, array $datos, User $actor, ?EvaluacionPeriodoPrueba $evaluacion = null): CierreLaboral
    {
        $tipoBaja = TipoBaja::from((string) $datos['tipo_baja']);
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        $fechaEfectiva = isset($datos['fecha_efectiva']) && is_string($datos['fecha_efectiva']) && $datos['fecha_efectiva'] !== ''
            ? $datos['fecha_efectiva']
            : ($evaluacion?->contrato?->fecha_fin?->toDateString() ?? FechaMexico::hoy());

        // Vista previa: la causal es opcional (si viene y es del tipo, su
        // nombre es el motivo por defecto, igual que al confirmar).
        $causalId = $datos['causal_rotacion_id'] ?? null;
        $causal = is_numeric($causalId) ? CausalRotacion::query()->where('id', (int) $causalId)->where('tipo', $tipoBaja->value)->first() : null;
        $cierre = $this->nuevoCierre($colaborador, $tipoBaja, $motivo !== '' ? $motivo : ($causal->nombre ?? $tipoBaja->etiqueta()), $fechaEfectiva, $actor, null, $evaluacion?->id, null, $causal?->id);
        $cierre->setRelation('colaborador', $colaborador);

        if ($evaluacion !== null) {
            $autorizada = $evaluacion->replicate();
            $autorizada->id = $evaluacion->id;
            $autorizada->forceFill(['estado' => EstadoEvaluacionPrueba::Autorizada, 'decision_renovar' => false]);
            $cierre->setRelation('evaluacion', $autorizada);
        }

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

        // Rescisión: por decisión del negocio NO se genera aviso de rescisión
        // (la baja se aplica y sigue el finiquito).
        if ($cierre->tipo_baja === TipoBaja::Rescision) {
            throw ValidationException::withMessages(['aviso' => 'La rescisión no genera aviso: la baja ya está aplicada y sigue el finiquito.']);
        }

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

        if ($cierre->fecha_efectiva->isAfter(FechaMexico::inicioDeHoy())) {
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
     * LEGACY (flujo anterior del finiquito): ya no tiene ruta ni acción (RH no
     * autoriza el finiquito: lo calcula y registra su entrega con
     * registrarEntregaFiniquito()). Bloqueado salvo features.cierre_flujo_legacy;
     * se conserva solo para reproducir/cerrar cierres del flujo anterior en
     * datos de demostración y pruebas.
     */
    public function autorizarFiniquito(CierreLaboral $cierre, User $actor): CierreLaboral
    {
        $this->exigirFlujoLegacyHabilitado();

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
     * LEGACY (flujo anterior del finiquito): la regional de coordinación
     * programaba el pago. Bloqueado salvo features.cierre_flujo_legacy — hoy
     * RH registra la entrega del finiquito (registrarEntregaFiniquito), que
     * cierra el cierre laboral.
     *
     * @param  array{fecha: string, monto?: float|int|string|null, metodo: string, responsable_user_id?: int|null, observaciones?: string|null}  $datos
     */
    public function programarPago(CierreLaboral $cierre, User $actor, array $datos): CierreLaboral
    {
        $this->exigirFlujoLegacyHabilitado();
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
     * LEGACY (flujo anterior del finiquito): el gerente registraba la cita con
     * el excolaborador para firma y pago. Bloqueado salvo
     * features.cierre_flujo_legacy.
     */
    public function registrarCita(CierreLaboral $cierre, User $actor, string $fecha): CierreLaboral
    {
        $this->exigirFlujoLegacyHabilitado();
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
     * LEGACY (flujo anterior del finiquito): firma y huella del finiquito
     * (después de programar el pago). Bloqueado salvo
     * features.cierre_flujo_legacy.
     */
    public function registrarFiniquitoFirmado(CierreLaboral $cierre, UploadedFile $archivo, User $actor): CierreLaboral
    {
        $this->exigirFlujoLegacyHabilitado();
        $this->exigirOperacion($cierre, $actor);
        $this->exigirEstado($cierre, [EstadoCierreLaboral::PagoProgramado, EstadoCierreLaboral::FiniquitoFirmado, EstadoCierreLaboral::Pagado]);
        $this->finiquitos->subirFirmado($this->exigirFiniquito($cierre), $archivo, $actor);
        $cierre->update(['estado' => $this->avanzar($cierre, EstadoCierreLaboral::FiniquitoFirmado)]);
        $this->auditoria->registrar('finiquito_firmado', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id]);
        $this->sincronizarPendientes($cierre->refresh(), $actor);

        return $cierre;
    }

    /**
     * LEGACY (flujo anterior del finiquito): confirmación del pago con
     * referencia. Bloqueado salvo features.cierre_flujo_legacy.
     */
    public function confirmarPago(CierreLaboral $cierre, User $actor, string $referencia): CierreLaboral
    {
        $this->exigirFlujoLegacyHabilitado();
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
     * vacante sincronizada) — solo con la baja aplicada, el finiquito según
     * config/contratos.php (cierre.requiere_*) y a partir de la fecha
     * efectiva. En el flujo vigente la dispara registrarEntregaFiniquito().
     */
    public function ejecutarBaja(CierreLaboral $cierre, User $actor, bool $exigirFiniquito = true): CierreLaboral
    {
        $this->exigirNoFinal($cierre);
        $this->exigirAutorizado($cierre);

        if ($cierre->estado === EstadoCierreLaboral::BajaEjecutada) {
            throw ValidationException::withMessages(['estado' => 'La baja ya fue ejecutada.']);
        }

        if ($cierre->fecha_efectiva->isAfter(FechaMexico::inicioDeHoy())) {
            throw ValidationException::withMessages(['fecha_efectiva' => sprintf('La baja no puede ejecutarse antes de su fecha efectiva (%s).', $cierre->fecha_efectiva->format('d/m/Y'))]);
        }

        // Cerrar la baja desde su expediente (BajaService::cerrar) no espera al
        // finiquito: RH ya validó que sus documentos obligatorios estén
        // completos; el finiquito se sigue registrando aquí después.
        $finiquito = $exigirFiniquito ? $this->exigirFiniquito($cierre) : $this->finiquito($cierre);

        // Procedimiento integral de baja, escenario B: si el colaborador se
        // negó a firmar/recibir, el finiquito queda a su disposición (y en su
        // caso se consigna); la baja operativa no espera su firma ni su pago.
        $aDisposicion = $cierre->negativa_firma_en !== null && $cierre->finiquito_a_disposicion;

        if ($exigirFiniquito && $finiquito !== null && ! $aDisposicion && config('contratos.cierre.requiere_finiquito_firmado') && ! in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
            throw ValidationException::withMessages(['finiquito' => 'El finiquito debe estar firmado antes de ejecutar la baja.']);
        }

        if ($exigirFiniquito && $finiquito !== null && ! $aDisposicion && config('contratos.cierre.requiere_pago_confirmado') && $finiquito->pagado_en === null) {
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

    /**
     * La baja cambió de forma, causal o fecha (el gerente la corrigió tras un
     * rechazo, o RH corrigió la causal): el cierre y su solicitud quedan
     * iguales y se generan los documentos de la nueva causal que falten
     * (los ya generados no se tocan).
     */
    public function sincronizarDesdeBaja(CierreLaboral $cierre, Baja $baja, User $actor): void
    {
        if ($cierre->estado->esFinal() || $baja->forma_salida === null) {
            return;
        }

        $cambios = array_filter([
            'tipo_baja' => $baja->forma_salida->tipoBaja(),
            'causal_rotacion_id' => $baja->causal_rotacion_id,
            'fecha_efectiva' => $baja->fecha_baja?->toDateString() ?? $cierre->fecha_efectiva->toDateString(),
        ], fn (mixed $v): bool => $v !== null);

        DB::transaction(function () use ($cierre, $cambios): void {
            $cierre->update($cambios);
            $cierre->solicitud?->update(array_intersect_key($cambios, array_flip(['tipo_baja', 'causal_rotacion_id', 'fecha_efectiva'])));
        });

        try {
            $cierre->refresh();
            $this->generarDocumentosDeCausa($cierre, $actor, $this->documentosProceso->clavesCausa($cierre));
        } catch (Throwable $e) {
            Log::warning('CierreLaboralService: no se pudieron preparar los documentos de la causal corregida.', ['cierre_id' => $cierre->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Reenvío de una baja rechazada: si RH le devolvió el acceso mientras el
     * gerente la corregía, se vuelve a suspender (misma regla que al enviar).
     */
    public function suspenderAccesoPorBaja(CierreLaboral $cierre, User $actor): void
    {
        $cuenta = $cierre->colaborador->user;

        if ($cuenta === null || $cuenta->acceso_bloqueado_en !== null || $cierre->estado->esFinal()) {
            return;
        }

        $this->acceso->revocar($cuenta, $actor, sprintf('%s %s', self::MOTIVO_SUSPENSION, $cierre->tipo_baja->etiqueta()));
        $cierre->update(['acceso_suspendido_en' => now()]);
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
            // La baja ligada queda «No procede» (nunca se borra).
            $this->bajas->alCancelarCierre($cierre, $actor, $motivo);
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
            ->whereDate('fecha_efectiva', '<=', FechaMexico::hoy())
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
     * Panorama de bajas y finiquitos para el índice web (Rh/Cierres/Index.vue):
     * lista liviana (sin documentos ni aprobaciones por fila), con el MISMO
     * alcance del módulo «cierres» que listar(). La fila se arma con
     * filaPanorama(); las acciones se ejecutan en la ficha per-persona, nunca
     * desde aquí (y NUNCA las del flujo legacy).
     *
     * @param  array<string, mixed>  $filtros  estado?, busqueda?, per_page?
     * @return LengthAwarePaginator<int, CierreLaboral>
     */
    public function panorama(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = CierreLaboral::query()->with([
            'colaborador:id,name,apellidos,numero_empleado,puesto_id,sucursal_principal_id,jefe_id,gerente_id,foto_path',
            'colaborador.puesto:id,nombre',
            'colaborador.sucursalPrincipal:id,nombre',
            'iniciadoPor:id,name,apellidos,colaborador_id',
            'iniciadoPor.colaborador',
            'causalRotacion:id,nombre',
        ]);

        $this->alcance->limitarPorModulo($query, $usuario, 'cierres', 'colaborador_id', true);

        $estado = isset($filtros['estado']) && is_string($filtros['estado']) && $filtros['estado'] !== '' ? $filtros['estado'] : null;
        $busqueda = isset($filtros['busqueda']) && is_string($filtros['busqueda']) ? trim($filtros['busqueda']) : '';

        return $query
            ->when($estado === 'abiertos', fn (Builder $q) => $q->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos())))
            ->when($estado !== null && $estado !== 'abiertos', fn (Builder $q) => $q->where('estado', $estado))
            ->when($busqueda !== '', fn (Builder $q) => $q->whereHas('colaborador', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                ->where('name', 'like', sprintf('%%%s%%', $busqueda))
                ->orWhere('apellidos', 'like', sprintf('%%%s%%', $busqueda))
                ->orWhere('numero_empleado', 'like', sprintf('%%%s%%', $busqueda)))))
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null));
    }

    /**
     * Finiquitos de una página de cierres, indexados por la solicitud interna
     * que comparten cierre y finiquito. Un solo query (evita N+1 en el índice).
     *
     * @param  iterable<int, CierreLaboral>  $cierres
     * @return SupportCollection<int, FiniquitoCalculo>
     */
    public function finiquitosDeCierres(iterable $cierres): SupportCollection
    {
        $solicitudIds = collect($cierres)
            ->map(fn (CierreLaboral $c): ?int => $c->solicitud_interna_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($solicitudIds === []) {
            return collect();
        }

        return FiniquitoCalculo::query()
            ->whereIn('solicitud_interna_id', $solicitudIds)
            ->get()
            ->keyBy('solicitud_interna_id');
    }

    /**
     * Fila del panorama: datos del colaborador, estado del cierre y (si el
     * usuario tiene finiquitos.ver) el estado y neto del finiquito, más la
     * etiqueta del siguiente paso vigente.
     *
     * @return array<string, mixed>
     */
    public function filaPanorama(CierreLaboral $cierre, ?FiniquitoCalculo $finiquito, bool $puedeVerFiniquito, ?User $viewer = null): array
    {
        return [
            'id' => $cierre->id,
            'colaborador' => [
                'id' => $cierre->colaborador->id,
                'nombre' => $cierre->colaborador->nombreCompleto(),
                'numero_empleado' => $cierre->colaborador->numero_empleado,
                'puesto' => $cierre->colaborador->puesto?->nombre,
                'sucursal' => $cierre->colaborador->sucursalPrincipal?->nombre,
                // Foto oficial del expediente (URL protegida, dentro del alcance).
                'foto_url' => $viewer !== null ? $this->fotos->urlPara($cierre->colaborador, $viewer) : null,
            ],
            'tipo_baja' => $cierre->tipo_baja->value,
            'tipo_baja_etiqueta' => $cierre->tipo_baja->etiqueta(),
            'causal_rotacion' => $cierre->causalRotacion?->nombre,
            'fecha_efectiva' => $cierre->fecha_efectiva->toDateString(),
            'estado' => $cierre->estado->value,
            'estado_etiqueta' => $cierre->estado->etiqueta(),
            'solicitado_por' => $cierre->iniciadoPor?->nombreCompleto(),
            'finiquito' => $puedeVerFiniquito && $finiquito !== null ? [
                'estado' => $finiquito->estado->value,
                'estado_etiqueta' => $finiquito->estado->etiqueta(),
                'neto' => $finiquito->neto,
            ] : null,
            'siguiente_accion' => $this->siguienteAccionPanorama($cierre, $finiquito),
        ];
    }

    /**
     * Etiqueta corta del siguiente paso VIGENTE (nunca acciones del flujo
     * legacy: preautorizar / autorizar baja / programar pago / cita /
     * autorizar finiquito). Es informativa; la acción se ejecuta en la ficha.
     */
    private function siguienteAccionPanorama(CierreLaboral $cierre, ?FiniquitoCalculo $finiquito): string
    {
        if ($cierre->estado->esFinal()) {
            return $cierre->estado === EstadoCierreLaboral::ExpedienteCerrado ? 'Cierre concluido' : $cierre->estado->etiqueta();
        }

        if ($finiquito === null) {
            return 'Calcular finiquito';
        }

        if (! in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
            return 'Registrar entrega de finiquito';
        }

        return 'Concluir cierre laboral';
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

        // Cancelar tiene permiso propio (cierres.cancelar, RH por defecto).
        if ($usuario->can('cierres.cancelar') && ! $cierre->estado->esFinal() && $cierre->estado !== EstadoCierreLaboral::BajaEjecutada) {
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
        $cierre->loadMissing(['colaborador.sucursalPrincipal', 'iniciadoPor', 'pagoResponsable', 'causalRotacion']);

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
            'causal_rotacion_id' => $cierre->causal_rotacion_id,
            'causal_rotacion' => $cierre->causalRotacion?->etiquetaConClave(),
            // Expediente de la baja (módulo de Bajas): folio y estatus.
            'baja' => ($baja = $cierre->baja()->first()) !== null ? [
                'id' => $baja->id,
                'folio' => $baja->folio,
                'estatus' => $baja->estatus->value,
                'estatus_etiqueta' => $baja->estatus->etiqueta(),
            ] : null,
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

    /**
     * Pasos del FLUJO ANTERIOR del finiquito (autorizar → programar pago →
     * cita → firma → confirmar pago). El flujo vigente es: RH calcula/ajusta
     * el finiquito y registra su entrega (registrarEntregaFiniquito), que lo
     * congela y cierra el cierre — también los cierres que venían del tramo
     * anterior, para que ninguno se quede atorado. Estos pasos quedan
     * bloqueados salvo que features.cierre_flujo_legacy esté encendido (solo
     * para reproducir/cerrar expedientes legacy en pruebas y demostración).
     */
    private function exigirFlujoLegacyHabilitado(): void
    {
        if (! (bool) config('features.cierre_flujo_legacy', false)) {
            throw ValidationException::withMessages([
                'flujo' => 'Este paso pertenece al flujo anterior del finiquito. Ahora RH registra la entrega del finiquito, que cierra el cierre laboral.',
            ]);
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
