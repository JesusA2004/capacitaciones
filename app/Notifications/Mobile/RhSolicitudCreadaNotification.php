<?php

namespace App\Notifications\Mobile;

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Avisa a RH/aprobador que un colaborador envio una nueva solicitud interna.
 * Solo canal database: el push equivalente (type=rh_solicitud) lo encola por
 * separado App\Services\MobilePush\PushNotifier, ver
 * App\Services\Solicitudes\SolicitudesService::crear(). Ver
 * docs/PUSH_NOTIFICATIONS.md.
 */
class RhSolicitudCreadaNotification extends Notification implements ShouldQueue
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
            'tipo' => 'rh_solicitud',
            'titulo' => 'Nueva solicitud por revisar',
            'mensaje' => "Un colaborador envió una solicitud: {$this->solicitud->tipo->etiqueta()}.",
            'url' => null,
            'type' => 'rh_solicitud',
            'resource_id' => $this->solicitud->id,
        ];
    }
}
