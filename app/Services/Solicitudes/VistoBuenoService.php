<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta los vistos buenos jerárquicos (gerente → regional) sobre una
 * solicitud: registra la decisión del nivel pendiente; si alguien NO da el
 * visto bueno, la solicitud se rechaza con su motivo; con el último visto
 * bueno pasa sola a "Pendiente de autorizar" y llega a RH. Mismo
 * SolicitudesService que usa RH (sin duplicar el cambio de estado).
 */
class VistoBuenoService
{
    public function __construct(
        private readonly AprobacionJerarquicaService $aprobaciones,
        private readonly SolicitudesService $solicitudes,
        private readonly TareasSolicitudService $tareas,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function registrar(SolicitudInterna $solicitud, User $usuario, bool $aprobado, ?string $comentario): SolicitudAprobacion
    {
        $decision = DB::transaction(function () use ($solicitud, $usuario, $aprobado, $comentario): SolicitudAprobacion {
            $solicitud = SolicitudInterna::query()->lockForUpdate()->findOrFail($solicitud->id);
            $decision = $this->aprobaciones->registrarDecisionJefe($solicitud, $usuario, $aprobado, $comentario);
            $etiqueta = mb_strtolower(SolicitudAprobacion::etiquetaNivel($decision->nivel));

            $this->solicitudes->registrarHistorial($solicitud, $usuario, $aprobado ? "visto_bueno_{$etiqueta}" : "sin_visto_bueno_{$etiqueta}", $comentario);

            if (! $aprobado) {
                $this->solicitudes->rechazar($solicitud, $usuario, (string) $comentario);
            } elseif ($this->aprobaciones->tieneVistoBuenoJefe($solicitud) && $solicitud->estado === EstadoSolicitudInterna::Enviada) {
                // Último visto bueno: ya puede autorizarla RH.
                $this->solicitudes->marcarEnRevision($solicitud, $usuario, 'Con visto bueno del gerente y del regional.');
            }

            return $decision;
        });

        if ($aprobado) {
            $this->tareas->alDarVistoBueno($solicitud->refresh(), $usuario);
        }

        $this->auditoria->registrar($aprobado ? 'solicitud_visto_bueno' : 'solicitud_sin_visto_bueno', $solicitud, $usuario, [
            'nivel' => $decision->nivel,
            'comentario' => $comentario,
        ]);

        return $decision;
    }
}
