<?php

namespace App\Notifications\Bajas;

use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a la PERSONA sobre su propia baja (solo si RH eligió «Avisar al
 * colaborador», App\Services\Bajas\AvisoBajaColaboradorService): campana
 * web/app y correo. Sin causal, motivo, montos ni quién la capturó. Si la
 * persona no tiene cuenta, se manda solo el correo a su correo personal o
 * corporativo (notificación anónima).
 */
class AvisoBajaColaboradorNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(
        private readonly string $titulo,
        private readonly string $mensaje,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return filled($notifiable->email) ? ['database', 'broadcast', 'mail'] : ['database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->titulo)
            ->greeting('Hola')
            ->line($this->mensaje)
            ->line('Si tienes dudas, acércate a Recursos Humanos.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => 'baja_colaborador',
            'titulo' => $this->titulo,
            'mensaje' => $this->mensaje,
            'type' => 'baja_colaborador',
            'prioridad' => 'alta',
            'evento' => 'baja_aviso_colaborador',
        ];
    }
}
