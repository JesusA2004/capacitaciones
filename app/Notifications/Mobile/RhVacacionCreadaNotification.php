<?php

namespace App\Notifications\Mobile;

use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RhVacacionCreadaNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(private readonly SolicitudVacaciones $vacacion) {}

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
            'tipo' => 'rh_vacaciones',
            'titulo' => 'Nueva solicitud de vacaciones',
            'mensaje' => 'Un colaborador solicitó vacaciones y espera tu aprobación.',
            'url' => null,
            'type' => 'rh_vacaciones',
            'resource_id' => $this->vacacion->id,
        ];
    }
}
