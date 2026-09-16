<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Envía la contraseña nueva en texto plano por correo, una sola vez, tras
 * establecerla desde el panel (App\Services\Administracion\GeneradorPasswordService).
 * Envío síncrono (no ShouldQueue): es una acción puntual disparada por un
 * admin, no necesita cola. El texto plano nunca se persiste ni se loguea.
 */
class CredencialesActualizadasNotification extends Notification
{
    public function __construct(private readonly string $passwordNueva) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu contraseña de acceso fue actualizada')
            ->greeting("Hola {$notifiable->nombreCompleto()},")
            ->line('Un administrador estableció una nueva contraseña para tu cuenta en MR. LANA PEOPLE.')
            ->line("Contraseña nueva: **{$this->passwordNueva}**")
            ->line('Guárdala en un lugar seguro e inicia sesión para cambiarla cuando puedas.')
            ->line('Si no esperabas este cambio, contacta a Recursos Humanos de inmediato.')
            ->action('Iniciar sesión', route('login'));
    }
}
