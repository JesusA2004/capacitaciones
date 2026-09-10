<?php

namespace App\Notifications\Mobile;

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Felicitacion automatica de cumpleanos al colaborador (ver
 * App\Console\Commands\EnviarFelicitacionesCumpleanos y
 * App\Services\Cumpleanos\CumpleanosService::notificarColaborador). El push
 * equivalente se encola aparte via App\Services\MobilePush\PushNotifier.
 */
class BirthdayGreetingNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(private readonly BirthdayGreeting $greeting) {}

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
            'tipo' => 'cumpleanos',
            'titulo' => '¡Feliz cumpleaños!',
            'mensaje' => 'Hoy celebramos tu vida y todo lo que aportas a MR. LANA. Que tengas un gran día.',
            'url' => null,
            'type' => 'cumpleanos',
            'resource_id' => $this->greeting->id,
        ];
    }
}
