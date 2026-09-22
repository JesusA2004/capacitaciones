<?php

namespace App\Services\Solicitudes;

use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta el visto bueno del jefe inmediato sobre una solicitud de su
 * equipo: registra la decisión jerárquica y, si el jefe NO da el visto
 * bueno, rechaza la solicitud con su motivo (mismo SolicitudesService que
 * usa RH, sin duplicar la lógica de cambio de estado).
 */
class VistoBuenoService
{
    public function __construct(
        private readonly AprobacionJerarquicaService $aprobaciones,
        private readonly SolicitudesService $solicitudes,
        private readonly TareasSolicitudService $tareas,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function registrar(SolicitudInterna $solicitud, User $jefe, bool $aprobado, ?string $comentario): SolicitudAprobacion
    {
        $decision = DB::transaction(function () use ($solicitud, $jefe, $aprobado, $comentario): SolicitudAprobacion {
            $solicitud = SolicitudInterna::query()->lockForUpdate()->findOrFail($solicitud->id);
            $decision = $this->aprobaciones->registrarDecisionJefe($solicitud, $jefe, $aprobado, $comentario);

            $this->solicitudes->registrarHistorial($solicitud, $jefe, $aprobado ? 'visto_bueno_jefe' : 'sin_visto_bueno_jefe', $comentario);

            if (! $aprobado) {
                $this->solicitudes->rechazar($solicitud, $jefe, (string) $comentario);
            }

            return $decision;
        });

        if ($aprobado) {
            $this->tareas->alDarVistoBueno($solicitud->refresh(), $jefe);
        }

        $this->auditoria->registrar($aprobado ? 'solicitud_visto_bueno' : 'solicitud_sin_visto_bueno', $solicitud, $jefe, ['comentario' => $comentario]);

        return $decision;
    }
}
