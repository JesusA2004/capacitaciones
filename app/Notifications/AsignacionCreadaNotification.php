<?php

namespace App\Notifications;

use App\Models\AsignacionUsuario;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AsignacionCreadaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly AsignacionUsuario $asignacionUsuario) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        $canales = ['database'];

        if ($notifiable->prefiereNotificacionPorCorreo('asignaciones')) {
            $canales[] = 'mail';
        }

        return $canales;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $nombreCurso = $this->nombreElemento();

        return (new MailMessage)
            ->subject('Nueva capacitación asignada')
            ->greeting("Hola {$notifiable->name},")
            ->line("Se te asignó: «{$nombreCurso}».")
            ->when($this->asignacionUsuario->fecha_limite, fn (MailMessage $mensaje) => $mensaje->line(
                'Fecha límite: '.$this->asignacionUsuario->fecha_limite->format('d/m/Y'),
            ))
            ->action('Ir a Mi capacitación', route('mi-capacitacion.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => 'asignacion_creada',
            'asignacion_usuario_id' => $this->asignacionUsuario->id,
            'titulo' => 'Nueva capacitación asignada',
            'mensaje' => "Se te asignó: «{$this->nombreElemento()}».",
            'url' => route('mi-capacitacion.index'),
        ];
    }

    private function nombreElemento(): string
    {
        $this->asignacionUsuario->loadMissing('asignacion');

        return $this->asignacionUsuario->asignacion->nombre;
    }
}
