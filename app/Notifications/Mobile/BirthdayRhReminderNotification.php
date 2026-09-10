<?php

namespace App\Notifications\Mobile;

use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Recordatorio diario a RH/admin (permiso rh.cumpleanos.ver): cuantos
 * colaboradores cumplen anios hoy y en los proximos 7 dias, calculado
 * dentro del alcance organizacional de cada destinatario (ver
 * App\Services\Cumpleanos\CumpleanosService::notificarRh() —
 * App\Console\Commands\RecordarCumpleanosRh). Nunca incluye nombres ni
 * fecha completa de nacimiento.
 *
 * `resourceId` nunca es un valor inventado (como un timestamp): es null
 * salvo que el aviso apunte a una unica felicitacion concreta (por ejemplo,
 * un solo colaborador cumple anios hoy dentro del alcance de este
 * destinatario), en cuyo caso es el id real de ese BirthdayGreeting. La app
 * navega con `route` + `periodo` cuando no hay un recurso puntual.
 */
class BirthdayRhReminderNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(
        private readonly int $hoyCount,
        private readonly int $proximos7Count,
        private readonly ?int $resourceId = null,
        private readonly string $periodo = 'hoy',
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
            'resource_id' => $this->resourceId,
            'route' => 'rh/cumpleanos',
            'periodo' => $this->periodo,
        ];
    }
}
