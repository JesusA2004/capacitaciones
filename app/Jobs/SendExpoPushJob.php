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
use RuntimeException;
use Throwable;

/**
 * Envia un push individual via Expo, en la cola dedicada de avisos
 * (config('queue.cola_notificaciones'), worker
 * deploy/supervisor/people-notifications.conf). Siempre se encola DESPUES
 * del commit del evento principal (queue.connections.*.after_commit): un
 * fallo aqui nunca deshace ni bloquea esa accion.
 *
 * Resultados (ver App\Services\MobilePush\ExpoPushService):
 * - token invalido: el dispositivo ya quedo revocado; no se reintenta.
 * - Expo caido / limite de envio: se re-encola con espera y, si se agotan
 *   los intentos, el job FALLA de verdad (queda en failed_jobs y en
 *   `people:diagnostico-notificaciones`) en vez de desaparecer.
 * - error de configuracion (credenciales FCM/APNs, mensaje muy grande):
 *   falla de inmediato, para que sea visible.
 */
class SendExpoPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $data  Siempre trae 'type' y 'resource_id'; nunca PII sensible.
     */
    public function __construct(
        public readonly string $token,
        public readonly string $titulo,
        public readonly string $cuerpo,
        public readonly array $data = [],
    ) {
        $this->onQueue((string) config('queue.cola_notificaciones', 'notifications'));
    }

    public function handle(ExpoPushService $expo, MonitorNotificacionesService $monitor): void
    {
        $resultado = $expo->enviarConResultado($this->token, $this->titulo, $this->cuerpo, $this->data);

        if ($resultado === ExpoPushService::RESULTADO_ENVIADO) {
            $monitor->registrar(MonitorNotificacionesService::ULTIMO_PUSH);

            return;
        }

        if ($resultado === ExpoPushService::RESULTADO_REINTENTAR) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff * $this->attempts());

                return;
            }

            throw new RuntimeException('Expo push: el servicio no respondió tras todos los reintentos.');
        }

        if ($resultado === ExpoPushService::RESULTADO_ERROR) {
            // Error de configuración o del mensaje: reintentar no lo arregla,
            // pero tampoco debe esconderse.
            $this->fail(new RuntimeException('Expo push: error no recuperable (ver log «Expo push»).'));
        }
    }

    /**
     * Falla definitiva: el aviso de base de datos ya existe (la campana lo
     * muestra), solo se perdió el push. Queda en failed_jobs, en el log y en
     * el diagnóstico — nunca un catch vacío.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SendExpoPushJob: push fallido definitivamente.', [
            'type' => $this->data['type'] ?? null,
            'resource_id' => $this->data['resource_id'] ?? null,
            'user_id' => $this->data['user_id'] ?? null,
            'error' => $exception?->getMessage(),
        ]);

        app(MonitorNotificacionesService::class)->registrar(MonitorNotificacionesService::ULTIMO_FALLO_PUSH);
    }
}
