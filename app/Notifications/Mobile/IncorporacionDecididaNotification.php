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
            // Lenguaje llano para la persona: nunca nombres de etapas internas.
            'titulo' => $this->aprobada ? 'Tus documentos fueron aprobados' : 'Revisa tus documentos',
            'mensaje' => $this->aprobada
                ? 'Recursos Humanos aprobó tus documentos. En tu portal verás lo que sigue.'
                : 'Recursos Humanos te pidió revisar tus documentos. Entra a tu portal para ver qué corregir.',
            'url' => null,
            'type' => 'incorporacion',
            'resource_id' => $notifiable->id,
        ];
    }
}
