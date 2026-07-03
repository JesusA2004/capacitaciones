<?php

namespace App\Notifications;

use App\Models\AsignacionUsuario;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FechaLimiteProximaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly AsignacionUsuario $asignacionUsuario) {}

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
        $this->asignacionUsuario->loadMissing('asignacion');

        return (new MailMessage)
            ->subject('Tienes una capacitación por vencer')
            ->greeting("Hola {$notifiable->name},")
            ->line("«{$this->asignacionUsuario->asignacion->nombre}» vence el ".$this->asignacionUsuario->fecha_limite->format('d/m/Y').'.')
            ->action('Ir a Mi capacitación', route('mi-capacitacion.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $this->asignacionUsuario->loadMissing('asignacion');

        return [
            'tipo' => 'fecha_limite_proxima',
            'asignacion_usuario_id' => $this->asignacionUsuario->id,
            'titulo' => 'Tienes una capacitación por vencer',
            'mensaje' => "«{$this->asignacionUsuario->asignacion->nombre}» vence el ".$this->asignacionUsuario->fecha_limite->format('d/m/Y').'.',
            'url' => route('mi-capacitacion.index'),
        ];
    }
}
