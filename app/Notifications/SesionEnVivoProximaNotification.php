<?php

namespace App\Notifications;

use App\Models\SesionEnVivo;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SesionEnVivoProximaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SesionEnVivo $sesion) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        $canales = ['database'];

        if ($notifiable->prefiereNotificacionPorCorreo('recordatorios')) {
            $canales[] = 'mail';
        }

        return $canales;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mensaje = (new MailMessage)
            ->subject('Tu sesión en vivo está por comenzar')
            ->greeting("Hola {$notifiable->name},")
            ->line("«{$this->sesion->titulo}» comienza el ".$this->sesion->fecha_inicio->format('d/m/Y H:i').'.');

        if ($this->sesion->enlace_reunion) {
            $mensaje->action('Unirse a la sesión', $this->sesion->enlace_reunion);
        }

        return $mensaje;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => 'sesion_proxima',
            'sesion_id' => $this->sesion->id,
            'titulo' => 'Tu sesión en vivo está por comenzar',
            'mensaje' => "«{$this->sesion->titulo}» comienza el ".$this->sesion->fecha_inicio->format('d/m/Y H:i').'.',
            'url' => $this->sesion->enlace_reunion ?? route('mi-capacitacion.index'),
        ];
    }
}
