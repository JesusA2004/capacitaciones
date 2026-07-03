<?php

namespace App\Notifications;

use App\Models\EntregaActividad;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActividadCalificadaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly EntregaActividad $entrega) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        $canales = ['database'];

        if ($notifiable->prefiereNotificacionPorCorreo('calificaciones')) {
            $canales[] = 'mail';
        }

        return $canales;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $this->entrega->loadMissing('actividad');
        $aprobada = $this->entrega->estado->value === 'aprobada';

        $mensaje = (new MailMessage)
            ->subject('Resultado de tu actividad')
            ->greeting("Hola {$notifiable->name},")
            ->line("Tu entrega de la actividad «{$this->entrega->actividad->titulo}» ya fue revisada.")
            ->line($aprobada ? 'Resultado: aprobada.' : 'Resultado: rechazada. Puedes volver a entregarla.');

        if ($this->entrega->retroalimentacion) {
            $mensaje->line("Retroalimentación: {$this->entrega->retroalimentacion}");
        }

        return $mensaje->action('Ver actividad', route('mi-capacitacion.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $this->entrega->loadMissing('actividad');

        return [
            'tipo' => 'actividad_calificada',
            'entrega_id' => $this->entrega->id,
            'titulo' => 'Resultado de tu actividad',
            'mensaje' => "«{$this->entrega->actividad->titulo}»: {$this->entrega->estado->value}.",
            'url' => route('mi-capacitacion.index'),
        ];
    }
}
