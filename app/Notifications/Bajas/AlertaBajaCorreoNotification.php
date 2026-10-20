<?php

namespace App\Notifications\Bajas;

use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta de una baja que además va por CORREO (RN-12: RV-04 «Relación con
 * el jefe o clima laboral» → correo a RH para la entrevista de salida por
 * RH y la revisión conforme a NOM-035). Mismo payload que las demás
 * notificaciones (campana web, app) + correo. La entrega pasa por la capa
 * única NotificacionEventosService (idempotencia, cola de avisos).
 */
class AlertaBajaCorreoNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(
        private readonly string $tipo,
        private readonly string $titulo,
        private readonly string $mensaje,
        private readonly int $bajaId,
        private readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'broadcast', 'mail'] : ['database', 'broadcast'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->titulo)
            ->greeting('Hola')
            ->line($this->mensaje)
            ->action('Ver la baja', $this->url);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'mensaje' => $this->mensaje,
            'url' => $this->url,
            'type' => $this->tipo,
            'resource_id' => $this->bajaId,
            'related_type' => 'Baja',
            'related_id' => $this->bajaId,
            'accion' => 'revisar_baja',
            'prioridad' => 'alta',
        ];
    }
}
