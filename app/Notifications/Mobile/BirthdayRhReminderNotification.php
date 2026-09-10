<?php

namespace App\Notifications\Mobile;

use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Recordatorio diario a RH/admin (permiso rh.cumpleanos.ver): cuantos
 * colaboradores cumplen anios hoy y en los proximos 7 dias. Nunca incluye
 * nombres ni fecha completa de nacimiento en la notificacion, solo el
 * conteo — el detalle vive en el modulo web (ver
 * App\Console\Commands\RecordarCumpleanosRh).
 */
class BirthdayRhReminderNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(
        private readonly int $hoyCount,
        private readonly int $proximos7Count,
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
        $mensaje = $this->hoyCount > 0
            ? "{$this->hoyCount} colaborador(es) cumplen años hoy."
            : "{$this->proximos7Count} cumpleaños en los próximos 7 días.";

        return [
            'tipo' => 'rh_cumpleanos',
            'titulo' => 'Cumpleaños de hoy',
            'mensaje' => $mensaje,
            'url' => null,
            'type' => 'rh_cumpleanos',
            'resource_id' => now()->timestamp,
        ];
    }
}
