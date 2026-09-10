<?php

namespace App\Notifications\Mobile;

use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RhIncorporacionCompletaNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(private readonly User $colaborador) {}

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
            'tipo' => 'rh_incorporacion',
            'titulo' => 'Incorporación lista para revisión final',
            'mensaje' => 'Un colaborador terminó de subir sus documentos de incorporación.',
            'url' => null,
            'type' => 'rh_incorporacion',
            'resource_id' => $this->colaborador->id,
        ];
    }
}
