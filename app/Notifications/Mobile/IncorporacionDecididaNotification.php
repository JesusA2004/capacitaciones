<?php

namespace App\Notifications\Mobile;

use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class IncorporacionDecididaNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(private readonly bool $aprobada) {}

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
            'tipo' => 'incorporacion',
            'titulo' => $this->aprobada ? 'Incorporación aprobada' : 'Incorporación rechazada',
            'mensaje' => $this->aprobada
                ? 'Tu incorporación fue aprobada. Ya tienes acceso completo.'
                : 'Tu incorporación fue rechazada. Revisa los detalles en la app.',
            'url' => null,
            'type' => 'incorporacion',
            'resource_id' => $notifiable->id,
        ];
    }
}
