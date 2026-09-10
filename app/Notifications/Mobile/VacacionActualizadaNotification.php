<?php

namespace App\Notifications\Mobile;

use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class VacacionActualizadaNotification extends Notification implements ShouldQueue
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
            'tipo' => 'vacaciones',
            'titulo' => 'Actualización de tus vacaciones',
            'mensaje' => "Tu solicitud de vacaciones ahora está: {$this->vacacion->estado->etiqueta()}.",
            'url' => null,
            'type' => 'vacaciones',
            'resource_id' => $this->vacacion->id,
        ];
    }
}
