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
            ->subject('Tus accesos a MR. LANA PEOPLE')
            ->greeting("Hola {$notifiable->nombreCompleto()},")
            ->line('Estos son tus accesos a MR. LANA PEOPLE.')
            ->line("Usuario: **{$notifiable->username}**")
            ->line("Contraseña temporal: **{$this->passwordNueva}**")
            ->line(sprintf('Entra en: %s', url('/')))
            ->line('Al iniciar sesión se te pedirá cambiar esta contraseña por una personal.')
            ->line('Si no esperabas este correo, contacta a Recursos Humanos de inmediato.')
            ->action('Iniciar sesión', route('login'));
    }
}
