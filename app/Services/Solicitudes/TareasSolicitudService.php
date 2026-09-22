<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\PrioridadTarea;
use App\Enums\TipoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;

/**
 * Pendientes de la bandeja de trabajo derivados de solicitudes internas
 * (vacaciones, permisos, préstamos): se abren al crear la solicitud (primero
 * para el jefe si el tipo exige su visto bueno, luego para quien autoriza) y
 * se resuelven al llegar a un estado final. Nunca interrumpe el flujo de la
 * solicitud (TareaService atrapa sus propios errores).
 */
class TareasSolicitudService
{
    public function __construct(
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AprobacionJerarquicaService $aprobaciones,
    ) {}

    public function alCrear(SolicitudInterna $solicitud): void
    {
        $tipo = $this->tipoTarea($solicitud);

        if ($tipo === null) {
            return;
        }

        $colaborador = $solicitud->personaSolicitante();

        if ($this->aprobaciones->requiereVistoBuenoJefe($solicitud) && ! $this->aprobaciones->tieneVistoBuenoJefe($solicitud)) {
            $jefe = $colaborador->jefe->user ?? $colaborador?->gerente?->user;

            if ($jefe !== null) {
                $this->tareas->abrir($tipo, $solicitud, [
                    'titulo' => sprintf('Visto bueno pendiente: %s %s', $solicitud->tipo->etiqueta(), $solicitud->folio),
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $colaborador,
                    'usuario' => $jefe,
                    'accion' => 'visto_bueno',
                ]);

                $this->notificador->notificar([$jefe], 'visto_bueno_pendiente', 'Solicitud por revisar de tu equipo', sprintf('%s solicitó %s.', $colaborador?->nombreCompleto() ?? 'Un colaborador', mb_strtolower($solicitud->tipo->etiqueta())), $solicitud, 'visto_bueno', 'alta');

                return;
            }
        }

        $this->abrirParaAutorizacion($solicitud, $tipo);
    }

    /**
     * Tras el visto bueno del jefe, el pendiente pasa a quien autoriza.
     */
    public function alDarVistoBueno(SolicitudInterna $solicitud, User $jefe): void
    {
        $tipo = $this->tipoTarea($solicitud);

        if ($tipo === null) {
            return;
        }

        $this->tareas->resolver($tipo, $solicitud, $jefe);
        $this->abrirParaAutorizacion($solicitud, $tipo);
    }

    public function alCambiarEstado(SolicitudInterna $solicitud, User $actor): void
    {
        $tipo = $this->tipoTarea($solicitud);

        if ($tipo === null) {
            return;
        }

        if (in_array($solicitud->estado, [EstadoSolicitudInterna::Aprobada, EstadoSolicitudInterna::Rechazada, EstadoSolicitudInterna::Cancelada, EstadoSolicitudInterna::Cerrada], true)) {
            $this->tareas->resolver($tipo, $solicitud, $actor);
        }
    }

    private function abrirParaAutorizacion(SolicitudInterna $solicitud, TipoTarea $tipo): void
    {
        $this->tareas->abrir($tipo, $solicitud, [
            'titulo' => sprintf('%s por autorizar: %s', $solicitud->tipo->etiqueta(), $solicitud->folio),
            'descripcion' => $solicitud->motivo,
            'colaborador' => $solicitud->personaSolicitante(),
            'permiso' => $solicitud->tipo === TipoSolicitudInterna::PrestamoInterno ? 'prestamos.autorizar' : 'rh.solicitudes.aprobar',
            'accion' => 'revisar_solicitud',
        ]);
    }

    private function tipoTarea(SolicitudInterna $solicitud): ?TipoTarea
    {
        return match ($solicitud->tipo) {
            TipoSolicitudInterna::Vacaciones => TipoTarea::VacacionesPendiente,
            TipoSolicitudInterna::PrestamoInterno => TipoTarea::PrestamoPendiente,
            TipoSolicitudInterna::PermisoConGoce,
            TipoSolicitudInterna::PermisoSinGoce,
            TipoSolicitudInterna::PermisoTiempo,
            TipoSolicitudInterna::SalidaTemprano,
            TipoSolicitudInterna::LlegadaTarde,
            TipoSolicitudInterna::PermisoEspecialCumpleanos,
            TipoSolicitudInterna::PermisoEspecialPaternidad,
            TipoSolicitudInterna::PermisoEspecialFallecimiento => TipoTarea::PermisoPendiente,
            default => null,
        };
    }
}
