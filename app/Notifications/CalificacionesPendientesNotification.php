<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Resumen diario para quien tenga el permiso respuestas.calificar: cuantos
 * intentos de cuestionario y entregas de actividad siguen pendientes de
 * revision manual. No identifica cada elemento (seria un correo enorme en
 * organizaciones grandes); solo avisa que hay trabajo pendiente y enlaza a
 * las pantallas de calificacion correspondientes.
 */
class CalificacionesPendientesNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $cuestionariosPendientes,
        private readonly int $actividadesPendientes,
    ) {}

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
        return (new MailMessage)
            ->subject('Tienes calificaciones pendientes')
            ->greeting("Hola {$notifiable->name},")
            ->line("Cuestionarios pendientes de calificar: {$this->cuestionariosPendientes}.")
            ->line("Actividades pendientes de calificar: {$this->actividadesPendientes}.")
            ->action('Calificar cuestionarios', route('calificaciones.cuestionarios.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => 'calificaciones_pendientes',
            'titulo' => 'Tienes calificaciones pendientes',
            'mensaje' => "Cuestionarios: {$this->cuestionariosPendientes}. Actividades: {$this->actividadesPendientes}.",
            'url' => route('calificaciones.cuestionarios.index'),
        ];
    }
}
