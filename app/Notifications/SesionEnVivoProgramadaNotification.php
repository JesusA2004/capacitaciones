<?php

namespace App\Notifications;

use App\Models\SesionEnVivo;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SesionEnVivoProgramadaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SesionEnVivo $sesion) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        $canales = ['database'];

        if ($notifiable->prefiereNotificacionPorCorreo('sesiones')) {
            $canales[] = 'mail';
        }

        return $canales;
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nueva sesión en vivo programada')
            ->greeting("Hola {$notifiable->name},")
            ->line("Se programó la sesión «{$this->sesion->titulo}».")
            ->line('Fecha: '.$this->sesion->fecha_inicio->format('d/m/Y H:i'))
            ->action('Ver detalle', route('mi-capacitacion.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => 'sesion_programada',
            'sesion_id' => $this->sesion->id,
            'titulo' => 'Nueva sesión en vivo programada',
            'mensaje' => "«{$this->sesion->titulo}» — ".$this->sesion->fecha_inicio->format('d/m/Y H:i'),
            'url' => route('mi-capacitacion.index'),
        ];
    }
}
