<?php

namespace App\Notifications\Mobile;

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Avisa al colaborador que su solicitud cambio de estado (aprobada,
 * rechazada, requiere correccion). Ver
 * App\Services\Solicitudes\SolicitudesService::cambiarEstado().
 */
class SolicitudActualizadaNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(private readonly SolicitudInterna $solicitud) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => 'solicitud',
            'titulo' => 'Actualización de tu solicitud',
            'mensaje' => "Tu solicitud «{$this->solicitud->folio}» ahora está: {$this->solicitud->estado->etiqueta()}.",
            'url' => null,
            'type' => 'solicitud',
            'resource_id' => $this->solicitud->id,
        ];
    }
}
