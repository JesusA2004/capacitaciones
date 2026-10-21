<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\PrioridadTarea;
use App\Enums\TipoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Models\SolicitudAprobacion;
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
        private readonly AprobacionOrganizacionalService $motor,
    ) {}

    /**
     * Nombre visible del nivel según el motor («Gerente Regional Q1»,
     * «Responsable de aprobación»…), con el catálogo fijo como respaldo.
     */
    private function etiquetaNivel(SolicitudInterna $solicitud, string $nivel): string
    {
        foreach ($this->motor->pasos($solicitud) as $paso) {
            if ($paso['nivel'] === $nivel) {
                return $paso['etiqueta'];
            }
        }

        return SolicitudAprobacion::etiquetaNivel($nivel);
    }

    public function alCrear(SolicitudInterna $solicitud): void
    {
        $tipo = $this->tipoTarea($solicitud);

        if ($tipo === null) {
            return;
        }

        if (! $this->pedirVistoBuenoPendiente($solicitud, $tipo)) {
            $this->abrirParaAutorizacion($solicitud, $tipo);
        }
    }

    /**
     * Tras un visto bueno, el pendiente pasa al siguiente nivel (regional) o,
     * si ya están todos, a quien autoriza.
     */
    public function alDarVistoBueno(SolicitudInterna $solicitud, User $usuario): void
    {
        $tipo = $this->tipoTarea($solicitud);

        if ($tipo === null) {
            return;
        }

        $this->tareas->resolver($tipo, $solicitud, $usuario);
        $colaborador = $solicitud->personaSolicitante();

        if ($this->pedirVistoBuenoPendiente($solicitud, $tipo)) {
            // Paso intermedio: el solicitante sabe quién ya aprobó y quién
            // sigue («aprobación realizada»).
            $siguiente = $this->motor->flujo($solicitud)['pendiente_de'];
            $this->notificador->notificar(
                [$solicitud->usuario],
                'solicitud_paso_aprobado',
                'Tu solicitud avanzó',
                sprintf('%s dio su visto bueno a tu solicitud %s.%s', $usuario->nombreCompleto(), $solicitud->folio, $siguiente !== null ? ' '.$siguiente['texto'].'.' : ''),
                $solicitud,
                'ver_solicitud',
            );

            return;
        }

        $this->abrirParaAutorizacion($solicitud, $tipo);

        // Último visto bueno: AHORA RH puede actuar → se le avisa (antes no).
        $rh = $this->motor->quienesPuedenActuar($solicitud)->reject(fn (User $u) => $u->id === $usuario->id)->values();
        $this->notificador->notificar(
            $rh,
            'solicitud_lista_para_autorizar',
            sprintf('%s lista para autorizar', $solicitud->tipo->etiqueta()),
            sprintf('%s ya tiene el visto bueno de %s. Te toca autorizarla.', $colaborador?->nombreCompleto() ?? 'La solicitud', $usuario->nombreCompleto()),
            $solicitud,
            'revisar_solicitud',
            'alta',
        );
    }

    /**
     * Vuelve a calcular a quién le toca el visto bueno de las solicitudes
     * abiertas de estas personas (cambió su responsable de aprobación o su
     * región Q1/Q3): cierra los pendientes de quien ya no responde y avisa
     * SOLO a quien puede actuar ahora.
     *
     * @param  iterable<int>  $colaboradorIds
     */
    public function reevaluarPendientes(iterable $colaboradorIds): void
    {
        $ids = collect($colaboradorIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return;
        }

        $solicitudes = SolicitudInterna::query()
            ->whereIn('colaborador_id', $ids)
            ->where('estado', EstadoSolicitudInterna::Enviada->value)
            ->get();

        foreach ($solicitudes as $solicitud) {
            $tipo = $this->tipoTarea($solicitud);

            if ($tipo === null || ! $this->aprobaciones->requiereVistoBuenoJefe($solicitud)) {
                continue;
            }

            $this->tareas->resolver($tipo, $solicitud);

            if (! $this->pedirVistoBuenoPendiente($solicitud, $tipo)) {
                $this->abrirParaAutorizacion($solicitud, $tipo);
            }
        }
    }

    /**
     * Abre el pendiente y avisa a quien debe dar el siguiente visto bueno
     * (responsable, regional, Dirección Comercial). false si ya no falta
     * ninguno. Si el paso está BLOQUEADO (p. ej. «Pendiente de asignar
     * región de aprobación») nadie puede darlo: se avisa a quien puede
     * corregir la estructura (organigrama.editar) y NO a RH como autorizador.
     */
    private function pedirVistoBuenoPendiente(SolicitudInterna $solicitud, TipoTarea $tipo): bool
    {
        $nivel = $this->aprobaciones->nivelPendiente($solicitud);
        $aprobadores = $this->aprobaciones->aprobadoresPendientes($solicitud);
        $colaborador = $solicitud->personaSolicitante();

        if ($nivel !== null && $aprobadores->isEmpty()) {
            $bloqueo = $this->aprobaciones->pendienteBloqueado($solicitud);

            if ($bloqueo === null) {
                return false;
            }

            if ($colaborador !== null) {
                $this->notificador->notificar(
                    $this->notificador->responsablesDe($colaborador, 'organigrama.editar'),
                    'solicitud_aprobacion_bloqueada',
                    'Solicitud detenida por el organigrama',
                    sprintf('La solicitud %s de %s no puede avanzar. %s', $solicitud->folio, $colaborador->nombreCompleto(), $bloqueo),
                    $solicitud,
                    'ver_organigrama',
                    'alta',
                );
            }

            return true;
        }

        if ($nivel === null) {
            return false;
        }

        $etiqueta = mb_strtolower($this->etiquetaNivel($solicitud, $nivel));

        foreach ($aprobadores as $aprobador) {
            $this->tareas->abrir($tipo, $solicitud, [
                'titulo' => sprintf('Visto bueno (%s): %s %s', $etiqueta, $solicitud->tipo->etiqueta(), $solicitud->folio),
                'prioridad' => PrioridadTarea::Alta,
                'colaborador' => $colaborador,
                'usuario' => $aprobador,
                'accion' => 'visto_bueno',
            ]);

            $this->notificador->notificarEvento('solicitud_visto_bueno', $colaborador, ['aprobador' => $aprobador, 'solicitante' => $solicitud->usuario], 'Solicitud por revisar de tu equipo', sprintf('%s solicitó %s. Falta tu visto bueno.', $colaborador?->nombreCompleto() ?? 'Un colaborador', mb_strtolower($solicitud->tipo->etiqueta())), $solicitud, 'visto_bueno', 'alta');
        }

        return true;
    }

    public function alCambiarEstado(SolicitudInterna $solicitud, User $actor): void
    {
        $tipo = $this->tipoTarea($solicitud);

        if ($tipo === null) {
            return;
        }

        if (in_array($solicitud->estado, [EstadoSolicitudInterna::Aprobada, EstadoSolicitudInterna::Rechazada, EstadoSolicitudInterna::Cancelada], true)) {
            $this->tareas->resolver($tipo, $solicitud, $actor);

            if ($tipo === TipoTarea::CorreccionPorRevisar) {
                $this->tareas->resolver(TipoTarea::CorreccionPorResponder, $solicitud, $actor);
            }

            return;
        }

        // Corrección: RH pidió más información → el pendiente pasa al
        // colaborador (su bandeja y el contador de la app) hasta que responda.
        if ($tipo === TipoTarea::CorreccionPorRevisar && $solicitud->estado === EstadoSolicitudInterna::RequiereCorreccion) {
            $this->tareas->resolver(TipoTarea::CorreccionPorRevisar, $solicitud, $actor);
            $this->tareas->abrir(TipoTarea::CorreccionPorResponder, $solicitud, [
                'titulo' => sprintf('Recursos Humanos necesita información: %s', $solicitud->folio),
                'colaborador' => $solicitud->personaSolicitante(),
                'usuario' => $solicitud->usuario,
                'accion' => 'responder_correccion',
                'prioridad' => PrioridadTarea::Alta,
            ]);
        }
    }

    /**
     * El colaborador respondió a la corrección (CorreccionesService::responder()):
     * su pendiente se cierra y vuelve a abrirse el de quien la revisa.
     */
    public function alResponderCorreccion(SolicitudInterna $solicitud, User $colaborador): void
    {
        $this->tareas->resolver(TipoTarea::CorreccionPorResponder, $solicitud, $colaborador);
        $this->abrirParaAutorizacion($solicitud, TipoTarea::CorreccionPorRevisar);
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
            TipoSolicitudInterna::Permiso,
            TipoSolicitudInterna::PermisoConGoce,
            TipoSolicitudInterna::PermisoSinGoce,
            TipoSolicitudInterna::PermisoTiempo,
            TipoSolicitudInterna::SalidaTemprano,
            TipoSolicitudInterna::LlegadaTarde,
            TipoSolicitudInterna::PermisoEspecialCumpleanos,
            TipoSolicitudInterna::PermisoEspecialPaternidad,
            TipoSolicitudInterna::PermisoEspecialFallecimiento => TipoTarea::PermisoPendiente,
            // Correcciones del expediente pedidas por el colaborador.
            TipoSolicitudInterna::ActualizacionDatos,
            TipoSolicitudInterna::ActualizacionBancaria,
            TipoSolicitudInterna::ReposicionDocumental => TipoTarea::CorreccionPorRevisar,
            default => null,
        };
    }
}
