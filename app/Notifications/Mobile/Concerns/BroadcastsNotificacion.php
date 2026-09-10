<?php

namespace App\Notifications\Mobile\Concerns;

use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Reenvia el mismo payload de toDatabase() por WebSocket (Laravel Reverb,
 * canal privado "App.Models.User.{id}", ver routes/channels.php) para que
 * el portal web muestre la notificacion en tiempo real sin esperar al
 * polling de 30s (resources/js/composables/useNotificaciones.ts) y pueda
 * disparar un toast/Notification nativo del navegador mientras la sesion
 * este abierta. Mismo evento (`notification.created`) para las 8
 * notificaciones moviles: el frontend no necesita conocer cada clase. Ver
 * docs/PUSH_NOTIFICATIONS.md.
 */
trait BroadcastsNotificacion
{
    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
