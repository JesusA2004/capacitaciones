<?php

namespace App\Notifications;

use App\Models\IntentoCuestionario;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CuestionarioCalificadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly IntentoCuestionario $intento) {}

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
        $this->intento->loadMissing('cuestionario');
        $aprobado = (bool) $this->intento->aprobado;

        return (new MailMessage)
            ->subject('Resultado de tu cuestionario')
            ->greeting("Hola {$notifiable->name},")
            ->line("Tu intento del cuestionario «{$this->intento->cuestionario->titulo}» ya fue calificado.")
            ->line("Calificación: {$this->intento->calificacion}%")
            ->line($aprobado ? 'Resultado: aprobado.' : 'Resultado: no aprobado.')
            ->action('Ver resultado', route('mi-capacitacion.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $this->intento->loadMissing('cuestionario');

        return [
            'tipo' => 'cuestionario_calificado',
            'intento_id' => $this->intento->id,
            'titulo' => 'Resultado de tu cuestionario',
            'mensaje' => "«{$this->intento->cuestionario->titulo}»: {$this->intento->calificacion}% (".
                ($this->intento->aprobado ? 'aprobado' : 'no aprobado').').',
            'url' => route('mi-capacitacion.index'),
        ];
    }
}
