<?php

namespace App\Jobs;

use App\Services\MobilePush\ExpoPushService;
use App\Services\Notificaciones\MonitorNotificacionesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consulta el recibo de entrega de un push ya aceptado por Expo
 * (App\Jobs\SendExpoPushJob lo encola con espera). Sin esto, un push que
 * Expo acepta pero FCM/APNs rechaza (credenciales del proyecto EAS, token
 * muerto) se pierde en silencio: el ticket dice "ok" y al teléfono no le
 * llega nada.
 *
 * - DeviceNotRegistered: el dispositivo queda revocado (ExpoPushService).
 * - Otro error: queda en el log y en el diagnóstico («Último push fallido»).
 * - Sin recibo todavía: se vuelve a consultar una vez más.
 * Nunca falla el job: es verificación, el aviso ya está en la campana.
 */
class VerificarReciboExpoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public readonly string $token,
        public readonly string $ticketId,
        public readonly ?string $tipo = null,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue((string) config('queue.cola_notificaciones', 'notifications'));
    }

    public function handle(ExpoPushService $expo, MonitorNotificacionesService $monitor): void
    {
        $recibo = $expo->recibo($this->token, $this->ticketId);

        if ($recibo['estado'] === 'error') {
            Log::warning('VerificarReciboExpoJob: el push no llegó al teléfono.', [
                'type' => $this->tipo,
                'user_id' => $this->userId,
                'error' => $recibo['error'],
                'detalle' => $recibo['detalle'],
            ]);

            $monitor->registrar(MonitorNotificacionesService::ULTIMO_FALLO_PUSH);

            return;
        }

        if (in_array($recibo['estado'], ['pendiente', 'desconocido'], true) && $this->attempts() < $this->tries) {
            $this->release(max(60, (int) config('expo.recibos_espera', 900)));
        }
    }
}
