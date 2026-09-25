<?php

namespace App\Notifications\Mobile;

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Aviso in-app de una celebración (docs/CELEBRACIONES.md). Tipos:
 *  - cumpleanos / aniversario_laboral: al homenajeado ("¡Felicidades!").
 *  - cumpleanos_general / aniversario_general: "Avisar a todos".
 *  - celebracion_mensaje: al homenajeado, nuevas felicitaciones (agrupado).
 *
 * Al abrirla, App\Services\Notificaciones\DestinoNotificacionService la
 * lleva a la pantalla del evento (/celebraciones/{id}), no a la bandeja.
 * El push equivalente se envía aparte (PushNotifier) con el mismo `type`,
 * `resource_id`, `related_type` y `accion`.
 */
class CelebracionNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(
        private readonly BirthdayGreeting $celebracion,
        private readonly string $tipo,
        private readonly string $titulo,
        private readonly string $mensaje,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => $this->tipo,
            'type' => $this->tipo,
            'titulo' => $this->titulo,
            'mensaje' => $this->mensaje,
            'resource_id' => $this->celebracion->id,
            'related_type' => 'Celebracion',
            'accion' => 'abrir_celebracion',
            'url' => route('celebraciones.show', $this->celebracion->id, false),
        ];
    }
}
