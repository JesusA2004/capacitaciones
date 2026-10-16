<?php

namespace App\Services\Notificaciones;

use App\Enums\CategoriaNotificacion;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Tareas\TareaService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Red de seguridad de los avisos (`php artisan notificaciones:reconciliar`,
 * cada minuto por el scheduler). Revisa SOLO pendientes abiertos y recientes
 * (ventana de 48 h por defecto, nunca toda la historia) y, si a quien debe
 * atenderlo no le llegó ningún aviso sobre ese recurso, se lo crea — una
 * sola vez (clave idempotente "tarea{id}" / "solicitud{id}").
 *
 * Cubre:
 *  - la bandeja de pendientes (tareas_rh abiertas): destinatarios =
 *    TareaService::destinatarios(), exactamente quien la ve en «Mis pendientes»;
 *  - solicitudes enviadas/en revisión: responsables por la regla de ruteo
 *    `solicitud_creada` y la confirmación al solicitante.
 *
 * Antes de reponer avisos cierra los pendientes que ya no son ciertos (sin
 * ventana de tiempo): «Faltan datos para …» de un cierre cuyo documento ya
 * se generó o cuyos datos ya se completaron — un pendiente viejo nunca debe
 * seguir pidiendo algo que ya se hizo.
 *
 * Deja un margen (`gracia`) para no adelantarse al flujo normal, que avisa
 * al instante.
 */
class ReconciliadorNotificacionesService
{
    public function __construct(
        private readonly NotificacionEventosService $eventos,
        private readonly TareaService $tareas,
        private readonly WorkflowRoutingService $routing,
        private readonly MonitorNotificacionesService $monitor,
        private readonly DocumentoProcesoService $documentosProceso,
    ) {}

    /**
     * @return array{revisados: int, creados: int, obsoletos: int}
     */
    public function reconciliar(int $horas = 48, int $graciaSegundos = 60): array
    {
        $desde = now()->subHours($horas);
        $hasta = now()->subSeconds($graciaSegundos);
        $resultado = ['revisados' => 0, 'creados' => 0, 'obsoletos' => $this->resolverObsoletos()];

        TareaRh::query()
            ->whereNull('resuelta_en')
            ->whereBetween('created_at', [$desde, $hasta])
            ->with('relacionado')
            ->chunkById(100, function (EloquentCollection $tareas) use (&$resultado): void {
                foreach ($tareas as $tarea) {
                    $resultado['revisados']++;
                    $resultado['creados'] += $this->reconciliarTarea($tarea);
                }
            });

        SolicitudInterna::query()
            ->whereIn('estado', [EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value])
            ->whereBetween('created_at', [$desde, $hasta])
            ->with(['usuario', 'colaborador', 'usuario.colaborador'])
            ->chunkById(100, function (EloquentCollection $solicitudes) use (&$resultado): void {
                foreach ($solicitudes as $solicitud) {
                    $resultado['revisados']++;
                    $resultado['creados'] += $this->reconciliarSolicitud($solicitud);
                }
            });

        $this->monitor->registrar(MonitorNotificacionesService::ULTIMA_RECONCILIACION);

        if ($resultado['creados'] > 0) {
            Log::info('notificaciones:reconciliar repuso avisos faltantes.', $resultado);
        }

        return $resultado;
    }

    /**
     * Pendientes documentales de cierres que ya no aplican (el documento ya
     * existe o ya no falta ningún dato): se resuelven. Una falla nunca
     * detiene la reconciliación de avisos.
     */
    private function resolverObsoletos(): int
    {
        try {
            $ids = TareaRh::query()
                ->whereNull('resuelta_en')
                ->where('tipo', TipoTarea::PlantillaFaltante->value)
                ->where('relacionado_type', (new CierreLaboral)->getMorphClass())
                ->distinct()
                ->pluck('relacionado_id');
            $resueltos = 0;

            foreach (CierreLaboral::query()->whereIn('id', $ids)->get() as $cierre) {
                $resueltos += $this->documentosProceso->resolverPendientesDocumentalesCierre($cierre);
            }

            return $resueltos;
        } catch (Throwable $e) {
            Log::warning('ReconciliadorNotificaciones: no se pudieron cerrar pendientes obsoletos.', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    private function reconciliarTarea(TareaRh $tarea): int
    {
        try {
            $recurso = $tarea->relacionado instanceof Model ? $tarea->relacionado : $tarea;
            $desde = $this->desde($tarea->created_at);
            $aviso = new Aviso(
                'pendiente_'.$tarea->tipo->value,
                $tarea->tipo->value,
                $tarea->titulo,
                $tarea->descripcion ?: $tarea->tipo->etiqueta(),
                $recurso,
                $tarea->accion,
                $tarea->prioridad->value,
                [],
                'tarea'.$tarea->id,
            );

            $creados = 0;

            foreach ($this->tareas->destinatarios($tarea) as $usuario) {
                if (! $this->eventos->yaAvisado($usuario, $recurso, $desde)
                    && $this->eventos->entregar($usuario, CategoriaNotificacion::Pendiente, $aviso, 'reconciliador')) {
                    $creados++;
                }
            }

            return $creados;
        } catch (Throwable $e) {
            Log::warning('ReconciliadorNotificaciones: falló una tarea.', ['tarea_id' => $tarea->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    private function reconciliarSolicitud(SolicitudInterna $solicitud): int
    {
        try {
            $solicitante = $solicitud->usuario;
            $persona = $solicitud->personaSolicitante();
            $desde = $this->desde($solicitud->created_at);
            $creados = 0;

            if ($solicitante instanceof User && ! $this->eventos->yaAvisado($solicitante, $solicitud, $desde)) {
                $confirmacion = new Aviso('solicitud_creada', 'solicitud', 'Solicitud enviada', sprintf('Tu solicitud %s quedó registrada y en revisión.', $solicitud->folio), $solicitud, 'registrada', 'media', [], 'solicitud'.$solicitud->id);
                $creados += $this->eventos->entregar($solicitante, CategoriaNotificacion::Confirmacion, $confirmacion, 'reconciliador') ? 1 : 0;
            }

            if ($persona === null) {
                return $creados;
            }

            $responsables = $this->routing->resolver('solicitud_creada', $persona, [
                'solicitante' => $solicitante,
                'excluir' => $solicitante !== null ? [$solicitante->id] : [],
            ])->pluck('usuario');
            $tarea = new Aviso('solicitud_creada', 'rh_solicitud', 'Nueva solicitud por revisar', sprintf('%s envió una solicitud (%s).', $persona->nombreCompleto(), mb_strtolower($solicitud->tipo->etiqueta())), $solicitud, 'revisar_solicitud', 'media', [], 'solicitud'.$solicitud->id);

            foreach ($responsables as $usuario) {
                if ($usuario instanceof User
                    && ! $this->eventos->yaAvisado($usuario, $solicitud, $desde)
                    && $this->eventos->entregar($usuario, CategoriaNotificacion::Pendiente, $tarea, 'reconciliador')) {
                    $creados++;
                }
            }

            return $creados;
        } catch (Throwable $e) {
            Log::warning('ReconciliadorNotificaciones: falló una solicitud.', ['solicitud_id' => $solicitud->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Un aviso cuenta si salió desde poco antes de crearse el pendiente
     * (el flujo normal avisa en la misma petición).
     */
    private function desde(?CarbonInterface $creado): CarbonInterface
    {
        return ($creado ?? now())->copy()->subMinutes(2);
    }
}
