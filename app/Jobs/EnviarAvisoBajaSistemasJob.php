<?php

namespace App\Jobs;

use App\Services\Notificaciones\AvisoBajaSistemasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Envía un correo de baja a Sistemas (dar de baja accesos / baja concluida /
 * baja cancelada) en la cola de avisos y siempre DESPUÉS del commit de la
 * baja. La idempotencia vive en el service (avisos_baja_sistemas): un
 * reintento después de un envío exitoso no manda otro correo. Si el envío
 * falla se re-encola con espera; agotados los intentos queda «fallido» y se
 * puede reenviar desde la bandeja.
 */
class EnviarAvisoBajaSistemasJob implements ShouldQueue
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

    public function handle(AvisoBajaSistemasService $avisos): void
    {
        $enviado = $avisos->enviar($this->avisoId, $this->actorId);

        if (! $enviado && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff * $this->attempts());
        }
    }
}
