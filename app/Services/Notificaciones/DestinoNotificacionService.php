<?php

namespace App\Services\Notificaciones;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Models\ContratoLaboral;
use App\Models\EmployeeDocument;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resuelve, al abrir una notificación, A DÓNDE debe llevar (pantalla web
 * del recurso exacto) y si lo que avisa YA FUE ATENDIDO — consultando el
 * estado actual del recurso, no el que tenía cuando se envió el aviso. Así,
 * si RH resolvió una solicitud desde la web y después abre el mismo aviso
 * en la app (o al revés), se le dice "ya fue atendida" en vez de invitarlo
 * a actuar sobre algo cerrado.
 *
 * Compartido por App\Http\Controllers\NotificacionController (web) y
 * Api\V1\NotificacionController (app móvil). La app navega nativamente con
 * `data.type`/`data.resource_id`; `url` es solo para la web.
 *
 * `atendida`:
 *  - true  → la acción que pedía el aviso ya se hizo (por quien sea).
 *  - false → sigue pendiente.
 *  - null  → aviso informativo (no pedía una acción) o recurso inexistente.
 *
 * @phpstan-type Destino array{url: string|null, atendida: bool|null, estado_recurso: string|null, mensaje_estado: string|null}
 */
class DestinoNotificacionService
{
    /**
     * @return Destino
     */
    public function resolver(DatabaseNotification $notificacion, User $usuario): array
    {
        $datos = $notificacion->data;
        $tipo = $this->texto($datos['type'] ?? null) ?? $this->texto($datos['tipo'] ?? null) ?? '';
        $relacionado = $this->texto($datos['related_type'] ?? null);
        $id = is_numeric($datos['resource_id'] ?? null) ? (int) $datos['resource_id'] : null;
        $urlGuardada = $this->texto($datos['url'] ?? null);

        try {
            $destino = match (true) {
                in_array($tipo, ['rh_solicitud', 'visto_bueno_pendiente', 'solicitud'], true) => $this->solicitud($id, $usuario),
                $tipo === 'rh_vacaciones' => $this->vacacionRh($id),
                $tipo === 'vacaciones' => $this->vacacionPropia($id),
                $tipo === 'rh_documento' => $this->documentoRh($id),
                $tipo === 'documento' => $this->documentoPropio($id),
                $tipo === 'rh_incorporacion' => $this->incorporacionRh($id),
                in_array($tipo, ['incorporacion', 'alta_activada', 'cumpleanos', 'cumpleanos_muro'], true) => self::destino(route('portal.index', [], false)),
                $tipo === 'expediente_incompleto' => self::destino(route('mi-expediente', ['tab' => 'documentos'], false)),
                $tipo === 'rh_cumpleanos' => self::destino(route('rh.cumpleanos.index', [], false)),
                $relacionado === 'EvaluacionPeriodoPrueba' => $this->evaluacion($id, $tipo),
                $relacionado === 'ContratoLaboral' => $this->contrato($id),
                $relacionado === 'GeneratedDocument' => $this->documentoLaboral($id),
                $relacionado === 'Prestamo' => self::destino(route('mi-expediente', ['tab' => 'prestamos'], false)),
                $relacionado === 'ReciboNomina' => self::destino(route('mi-expediente', ['tab' => 'recibos'], false)),
                default => self::destino(null),
            };
        } catch (Throwable $e) {
            // Resolver el destino es un extra: si falla, la notificación se
            // abre igual (con su url original, si la tenía).
            Log::warning('DestinoNotificacionService: no se pudo resolver el destino.', [
                'notificacion' => $notificacion->id,
                'tipo' => $tipo,
                'error' => $e->getMessage(),
            ]);
            $destino = self::destino(null);
        }

        if ($destino['url'] === null) {
            $destino['url'] = $urlGuardada;
        }

        return $destino;
    }

    /**
     * @return Destino
     */
    private function solicitud(?int $id, User $usuario): array
    {
        $solicitud = $id !== null ? SolicitudInterna::query()->with('revisadoPor:id,name,apellidos')->where('id', $id)->first() : null;

        if ($solicitud === null) {
            return self::inexistente(route('solicitudes.index', [], false), 'Esta solicitud ya no existe.');
        }

        $estado = $solicitud->estado;

        // El dueño consulta su propia solicitud (aviso informativo); RH o
        // su jefe la revisan desde la bandeja operativa.
        if ($usuario->id === $solicitud->user_id) {
            return self::destino(
                route('solicitudes.show', $solicitud->id, false),
                null,
                $estado->etiqueta(),
                sprintf('Tu solicitud %s está «%s».', $solicitud->folio, $estado->etiqueta()),
            );
        }

        $pendiente = in_array($estado, [EstadoSolicitudInterna::Creada, EstadoSolicitudInterna::Enviada, EstadoSolicitudInterna::EnRevision], true);
        $revisor = $solicitud->revisadoPor !== null
            ? trim(sprintf('%s %s', $solicitud->revisadoPor->name, $solicitud->revisadoPor->apellidos ?? ''))
            : null;

        return self::destino(
            route('rh.solicitudes.show', $solicitud->id, false),
            ! $pendiente,
            $estado->etiqueta(),
            $pendiente
                ? sprintf('La solicitud %s sigue pendiente («%s»).', $solicitud->folio, $estado->etiqueta())
                : sprintf('La solicitud %s ya fue atendida%s: está «%s».', $solicitud->folio, $revisor !== null && $revisor !== '' ? sprintf(' por %s', $revisor) : '', $estado->etiqueta()),
        );
    }

    /**
     * La revisión de vacaciones vive en la bandeja unificada de Solicitudes
     * (docs/SOLICITUDES_UNIFICADAS.md): no hay pantalla web por registro.
     *
     * @return Destino
     */
    private function vacacionRh(?int $id): array
    {
        $url = route('rh.solicitudes.index', [], false);
        $vacacion = $id !== null ? SolicitudVacaciones::query()->where('id', $id)->first() : null;

        if ($vacacion === null) {
            return self::inexistente($url, 'Esta solicitud de vacaciones ya no existe.');
        }

        $pendiente = $vacacion->estado === EstadoSolicitudVacaciones::Pendiente;

        return self::destino(
            $url,
            ! $pendiente,
            $vacacion->estado->etiqueta(),
            $pendiente
                ? 'Esta solicitud de vacaciones sigue pendiente de revisión.'
                : sprintf('Esta solicitud de vacaciones ya fue atendida: está «%s».', $vacacion->estado->etiqueta()),
        );
    }

    /**
     * @return Destino
     */
    private function vacacionPropia(?int $id): array
    {
        $url = route('mi-expediente', ['tab' => 'vacaciones'], false);
        $vacacion = $id !== null ? SolicitudVacaciones::query()->where('id', $id)->first() : null;

        if ($vacacion === null) {
            return self::inexistente($url, 'Esta solicitud de vacaciones ya no existe.');
        }

        return self::destino(
            $url,
            null,
            $vacacion->estado->etiqueta(),
            sprintf('Tu solicitud de vacaciones está «%s».', $vacacion->estado->etiqueta()),
        );
    }

    /**
     * @return Destino
     */
    private function documentoRh(?int $id): array
    {
        $documento = $id !== null ? EmployeeDocument::query()->with('tipo:id,nombre')->where('id', $id)->first() : null;

        if ($documento === null || $documento->colaborador_id === null) {
            return self::inexistente(route('rh.expedientes.index', [], false), 'Este documento ya no existe.');
        }

        $estado = $documento->status;
        $pendiente = in_array($estado, [EstadoDocumento::Cargado, EstadoDocumento::EnRevision, EstadoDocumento::CambioSolicitado], true);
        $nombre = $documento->tipo !== null ? sprintf('«%s»', $documento->tipo->nombre) : '';

        return self::destino(
            route('rh.expedientes.show', ['colaborador' => $documento->colaborador_id, 'tab' => 'documentos'], false),
            ! $pendiente,
            $estado->etiqueta(),
            $pendiente
                ? trim(sprintf('El documento %s sigue pendiente de revisión.', $nombre))
                : trim(sprintf('El documento %s ya fue revisado: está «%s».', $nombre, $estado->etiqueta())),
        );
    }

    /**
     * @return Destino
     */
    private function documentoPropio(?int $id): array
    {
        $url = route('mi-expediente', ['tab' => 'documentos'], false);
        $documento = $id !== null ? EmployeeDocument::query()->where('id', $id)->first() : null;

        if ($documento === null) {
            return self::inexistente($url, 'Este documento ya no existe.');
        }

        return self::destino(
            $url,
            null,
            $documento->status->etiqueta(),
            sprintf('Tu documento está «%s».', $documento->status->etiqueta()),
        );
    }

    /**
     * `resource_id` es el id del User que completó su incorporación.
     *
     * @return Destino
     */
    private function incorporacionRh(?int $idUsuario): array
    {
        $colaborador = $idUsuario !== null
            ? User::query()->with('colaborador')->where('id', $idUsuario)->first()?->colaborador
            : null;

        if ($colaborador === null) {
            return self::inexistente(route('rh.expedientes.index', [], false), 'Este colaborador ya no existe.');
        }

        $decision = $colaborador->incorporacion_decision;

        return self::destino(
            route('rh.expedientes.show', ['colaborador' => $colaborador->id, 'tab' => 'onboarding'], false),
            $decision !== null,
            $decision !== null ? ucfirst($decision) : 'Pendiente',
            $decision !== null
                ? sprintf('Esta incorporación ya fue atendida: quedó «%s».', $decision)
                : 'Esta incorporación sigue pendiente de revisión.',
        );
    }

    /**
     * `evaluacion_capturada` pide AUTORIZAR; `evaluacion_pendiente` y
     * `evaluacion_devuelta` piden CAPTURAR.
     *
     * @return Destino
     */
    private function evaluacion(?int $id, string $tipo): array
    {
        $evaluacion = $id !== null ? EvaluacionPeriodoPrueba::query()->where('id', $id)->first() : null;

        if ($evaluacion === null) {
            return self::inexistente(null, 'Esta evaluación ya no existe.');
        }

        $estado = $evaluacion->estado;
        $pendiente = $tipo === 'evaluacion_capturada'
            ? $estado === EstadoEvaluacionPrueba::Capturada
            : in_array($estado, [EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta], true);

        return self::destino(
            route('rh.expedientes.show', ['colaborador' => $evaluacion->colaborador_id, 'tab' => 'laborales'], false),
            ! $pendiente,
            $estado->etiqueta(),
            $pendiente
                ? sprintf('La evaluación sigue pendiente («%s»).', $estado->etiqueta())
                : sprintf('La evaluación ya fue atendida: está «%s».', $estado->etiqueta()),
        );
    }

    /**
     * @return Destino
     */
    private function contrato(?int $id): array
    {
        $contrato = $id !== null ? ContratoLaboral::query()->where('id', $id)->first() : null;

        if ($contrato === null) {
            return self::inexistente(route('rh.expedientes.index', [], false), 'Este contrato ya no existe.');
        }

        $pendiente = $contrato->estado === EstadoContratoLaboral::Vigente;

        return self::destino(
            route('rh.expedientes.show', ['colaborador' => $contrato->colaborador_id, 'tab' => 'laborales'], false),
            ! $pendiente,
            $contrato->estado->etiqueta(),
            $pendiente
                ? 'El contrato sigue vigente: aún falta decidir su renovación o término.'
                : sprintf('Este contrato ya fue atendido: está «%s».', $contrato->estado->etiqueta()),
        );
    }

    /**
     * Documento laboral por firmar (contrato, pagaré, etc.) del colaborador.
     *
     * @return Destino
     */
    private function documentoLaboral(?int $id): array
    {
        $url = route('mi-expediente', ['tab' => 'documentos'], false);
        $documento = $id !== null ? GeneratedDocument::query()->where('id', $id)->first() : null;
        $estado = $documento?->estado_flujo;

        if ($estado === null) {
            return self::inexistente($url, 'Este documento ya no existe.');
        }

        $atendido = $estado->estaFirmado() || $estado->esFinal();

        return self::destino(
            $url,
            $atendido,
            $estado->etiqueta(),
            $atendido
                ? sprintf('Este documento ya fue atendido: está «%s».', $estado->etiqueta())
                : 'Este documento sigue pendiente de tu firma.',
        );
    }

    /**
     * @return Destino
     */
    private static function destino(?string $url, ?bool $atendida = null, ?string $estado = null, ?string $mensaje = null): array
    {
        return [
            'url' => $url,
            'atendida' => $atendida,
            'estado_recurso' => $estado,
            'mensaje_estado' => $mensaje,
        ];
    }

    /**
     * @return Destino
     */
    private static function inexistente(?string $url, string $mensaje): array
    {
        return self::destino($url, null, null, $mensaje);
    }

    private function texto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
