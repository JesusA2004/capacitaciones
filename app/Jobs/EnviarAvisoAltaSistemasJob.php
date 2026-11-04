<?php

namespace App\Jobs;

use App\Services\Notificaciones\AvisoAltaSistemasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Envía el correo «Alta para asignar usuarios» a Sistemas, en la cola de
 * avisos (config('queue.cola_notificaciones'), worker
 * deploy/supervisor/people-notifications.conf) y siempre DESPUÉS del commit
 * de la contratación. La idempotencia vive en el service (avisos_alta_sistemas):
 * un reintento después de un envío exitoso no manda otro correo. Si el envío
 * falla se re-encola con espera; agotados los intentos, el aviso queda
 * «fallido» y RH puede reenviarlo a mano.
 */
class EnviarAvisoAltaSistemasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public int $timeout = 60;

    public function __construct(
        public readonly int $avisoId,
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue((string) config('queue.cola_notificaciones', 'notifications'));
        $this->afterCommit();
    }

    public function handle(AvisoAltaSistemasService $avisos): void
    {
        $enviado = $avisos->enviar($this->avisoId, $this->actorId);

        if (! $enviado && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff * $this->attempts());
        }
    }
}
