<?php

namespace App\Services\CierreLaboral;

use App\Models\CierreLaboral;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Tareas\NotificadorRhService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Etapas del "Procedimiento integral de baja de colaborador" (vencimiento
 * de capacitación inicial, art. 39-B / 53-I LFT) que el cierre laboral no
 * registraba. El procedimiento es un documento de negocio de RH/Legal: aquí
 * se implementa como flujo, nunca se imprime para el colaborador.
 *
 *  Fase 3 escenario B  negativa a firmar/recibir → acta con 2 testigos,
 *                      finiquito a su disposición (no se trata como firmado)
 *  Fase 4              notificación complementaria (correo/WhatsApp) + evidencia
 *  Fase 5              baja operativa: IMSS, asistencia, accesos, aviso interno
 *  Fase 6              consignación preventiva si no cobra el finiquito
 *  Fase 1              evidencia objetiva de desempeño (KPI's, reportes)
 *
 * No agrega obligaciones jurídicas que el procedimiento no pide.
 */
class ProcedimientoBajaService
{
    /** @var array<string, string> etapa => columna */
    public const ETAPAS = [
        'notificacion_electronica' => 'notificacion_electronica_en',
        'baja_imss' => 'baja_imss_en',
        'baja_asistencia' => 'baja_asistencia_en',
        'accesos_cancelados' => 'accesos_cancelados_en',
        'aviso_interno' => 'aviso_interno_en',
        'consignacion_preventiva' => 'consignacion_preventiva_en',
    ];

    public function __construct(
        private readonly SolicitudesService $solicitudes,
        private readonly AuditoriaService $auditoria,
        private readonly NotificadorRhService $notificador,
        private readonly DocumentoProcesoService $documentos,
    ) {}

    /**
     * El colaborador se negó a firmar/recibir los documentos de la baja.
     * Habilita el Acta administrativa de negativa (rama del proceso).
     *
     * @param  array{documentos?: list<string>, observaciones?: string|null, finiquito_a_disposicion?: bool, testigos?: list<array{nombre?: string|null, cargo?: string|null}>, participantes?: array<string, string|null>}  $datos
     */
    public function registrarNegativa(CierreLaboral $cierre, array $datos, User $actor): CierreLaboral
    {
        $this->exigirRh($actor);

        if ($cierre->estado->esFinal()) {
            throw ValidationException::withMessages(['cierre' => 'El cierre ya concluyó.']);
        }

        if (! $cierre->estado->autorizadoPorRh()) {
            throw ValidationException::withMessages(['cierre' => 'La baja aún no tiene autorización de RH: no hay documentos que entregar.']);
        }

        $entregables = $this->documentos->clavesCausa($cierre);
        $documentos = array_values(array_intersect((array) ($datos['documentos'] ?? $entregables), [...$entregables, 'finiquito']));

        if ($documentos === []) {
            throw ValidationException::withMessages(['documentos' => 'Indica qué documentos se intentaron entregar.']);
        }

        DB::transaction(function () use ($cierre, $datos, $actor, $documentos): void {
            $cierre->update([
                'negativa_firma_en' => now(),
                'negativa_firma_por' => $actor->id,
                'negativa_documentos' => $documentos,
                'negativa_observaciones' => $datos['observaciones'] ?? null,
                'finiquito_a_disposicion' => (bool) ($datos['finiquito_a_disposicion'] ?? true),
            ]);

            if (isset($datos['testigos']) || isset($datos['participantes'])) {
                $this->guardarTestigos($cierre, $datos['testigos'] ?? [], $datos['participantes'] ?? []);
            }
        });

        $this->auditoria->registrar('cierre_negativa_firma', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'documentos' => $documentos]);

        try {
            $this->notificador->notificarEvento(
                'cierre_negativa_firma',
                $cierre->colaborador,
                [],
                'Negativa de firma registrada',
                sprintf('%s se negó a firmar/recibir los documentos de su baja. Genera el acta de negativa con dos testigos.', $cierre->colaborador->nombreCompleto()),
                $cierre,
                'ver_cierre',
                'alta',
            );
        } catch (Throwable $e) {
            Log::warning('ProcedimientoBajaService: no se pudo notificar la negativa.', ['cierre_id' => $cierre->id, 'error' => $e->getMessage()]);
        }

        return $cierre->refresh();
    }

    /**
     * Testigos (nombre y cargo; la firma es física) y participantes del
     * acta (RH, jefe inmediato, lugar, hora).
     *
     * @param  list<array{nombre?: string|null, cargo?: string|null}>  $testigos
     * @param  array<string, string|null>  $participantes
     */
    public function capturarTestigos(CierreLaboral $cierre, array $testigos, array $participantes, User $actor): CierreLaboral
    {
        $this->exigirRh($actor);

        if ($cierre->negativa_firma_en === null) {
            throw ValidationException::withMessages(['cierre' => 'Primero registra la negativa del colaborador.']);
        }

        $this->guardarTestigos($cierre, $testigos, $participantes);
        $this->auditoria->registrar('cierre_testigos_acta', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'testigos' => count((array) $cierre->testigos)]);

        return $cierre->refresh();
    }

    /**
     * Marca una etapa operativa del procedimiento (notificación, IMSS,
     * asistencia, accesos, aviso interno, consignación). La evidencia
     * (capturas, correos, acuse) se adjunta a la solicitud de baja.
     *
     * @param  list<UploadedFile>  $evidencias
     * @param  list<string>  $medios
     */
    public function registrarEtapa(CierreLaboral $cierre, string $etapa, User $actor, array $evidencias = [], array $medios = [], ?string $observaciones = null): CierreLaboral
    {
        $this->exigirRh($actor);
        $columna = self::ETAPAS[$etapa] ?? null;

        if ($columna === null) {
            throw ValidationException::withMessages(['etapa' => 'Etapa desconocida.']);
        }

        if ($cierre->estado->esFinal()) {
            throw ValidationException::withMessages(['cierre' => 'El cierre ya concluyó.']);
        }

        if ($etapa === 'notificacion_electronica' && $evidencias === []) {
            throw ValidationException::withMessages(['evidencias' => 'Adjunta la evidencia de la notificación (captura de pantalla o correo enviado).']);
        }

        $solicitud = $cierre->solicitud;

        DB::transaction(function () use ($cierre, $etapa, $columna, $actor, $evidencias, $medios, $observaciones, $solicitud): void {
            $registradas = (array) ($cierre->evidencias ?? []);

            foreach ($evidencias as $archivo) {
                if ($solicitud === null) {
                    break;
                }

                $this->solicitudes->adjuntarDocumento($solicitud, $archivo, $actor);
                $registradas[] = [
                    'tipo' => $etapa,
                    'documento_id' => (int) $solicitud->documentos()->latest('id')->value('id'),
                    'nombre' => $archivo->getClientOriginalName(),
                ];
            }

            $cambios = [$columna => now(), 'evidencias' => $registradas];

            if ($etapa === 'notificacion_electronica') {
                $cambios['notificacion_electronica_por'] = $actor->id;
                $cambios['notificacion_medios'] = array_values(array_intersect($medios, ['correo', 'whatsapp'])) ?: ['correo'];
            }

            if ($etapa === 'consignacion_preventiva') {
                $cambios['consignacion_observaciones'] = $observaciones;
            }

            $cierre->update($cambios);
        });

        $this->auditoria->registrar('cierre_etapa_procedimiento', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'etapa' => $etapa, 'evidencias' => count($evidencias)]);

        return $cierre->refresh();
    }

    /**
     * Evidencia objetiva de desempeño (Fase 1): KPI's, reportes, incidencias.
     *
     * @param  list<UploadedFile>  $archivos
     */
    public function adjuntarEvidenciaDesempeno(CierreLaboral $cierre, array $archivos, User $actor): CierreLaboral
    {
        $this->exigirRh($actor);
        $solicitud = $cierre->solicitud;

        if ($solicitud === null || $archivos === []) {
            throw ValidationException::withMessages(['archivos' => 'Adjunta al menos un archivo.']);
        }

        $registradas = (array) ($cierre->evidencias ?? []);

        foreach ($archivos as $archivo) {
            $this->solicitudes->adjuntarDocumento($solicitud, $archivo, $actor);
            $registradas[] = ['tipo' => 'desempeno', 'documento_id' => (int) $solicitud->documentos()->latest('id')->value('id'), 'nombre' => $archivo->getClientOriginalName()];
        }

        $cierre->update(['evidencias' => $registradas]);
        $this->auditoria->registrar('cierre_evidencia_desempeno', $cierre, $actor, ['colaborador_id' => $cierre->colaborador_id, 'archivos' => count($archivos)]);

        return $cierre->refresh();
    }

    /**
     * @param  list<array{nombre?: string|null, cargo?: string|null}>  $testigos
     * @param  array<string, string|null>  $participantes
     */
    private function guardarTestigos(CierreLaboral $cierre, array $testigos, array $participantes): void
    {
        $limpios = [];

        foreach (array_slice($testigos, 0, 2) as $testigo) {
            $nombre = trim((string) ($testigo['nombre'] ?? ''));
            $cargo = trim((string) ($testigo['cargo'] ?? ''));

            if ($nombre === '' && $cargo === '') {
                continue;
            }

            if ($nombre === '' || $cargo === '') {
                throw ValidationException::withMessages(['testigos' => 'Cada testigo necesita nombre y cargo.']);
            }

            $limpios[] = ['nombre' => $nombre, 'cargo' => $cargo];
        }

        $permitidos = ['rh_nombre', 'rh_cargo', 'jefe_nombre', 'jefe_cargo', 'lugar_acta', 'domicilio_acta', 'hora_acta'];
        $actuales = (array) ($cierre->negativa_participantes ?? []);

        foreach ($participantes as $clave => $valor) {
            if (in_array($clave, $permitidos, true) && is_string($valor) && trim($valor) !== '') {
                $actuales[$clave] = trim($valor);
            }
        }

        if (isset($actuales['hora_acta']) && preg_match('/^\d{1,2}:\d{2}$/', $actuales['hora_acta']) !== 1) {
            throw ValidationException::withMessages(['participantes.hora_acta' => 'La hora debe tener el formato HH:MM.']);
        }

        $cierre->update(['testigos' => $limpios, 'negativa_participantes' => $actuales]);
    }

    private function exigirRh(User $actor): void
    {
        if (! $actor->can(CierreLaboralService::PERMISO_GESTIONAR) && ! $actor->can('documentos_laborales.generar')) {
            throw new AuthorizationException('Solo RH registra las etapas del procedimiento de baja.');
        }
    }
}
