<?php

namespace App\Jobs;

use App\Services\MobilePush\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Envia un push individual via Expo. Siempre se encola DESPUES de que el
 * evento principal (aprobar solicitud, subir documento, etc.) ya quedo
 * persistido en base de datos: un fallo aqui (Expo caido, token revocado)
 * nunca debe deshacer ni bloquear esa accion, solo se registra en el log
 * (ver App\Services\MobilePush\ExpoPushService). Ver docs/PUSH_NOTIFICATIONS.md.
 */
class SendExpoPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $data  Siempre trae 'type' y 'resource_id'; nunca PII sensible.
     */
    public function __construct(
        public readonly string $token,
        public readonly string $titulo,
        public readonly string $cuerpo,
        public readonly array $data = [],
    ) {}

    public function handle(ExpoPushService $expo): void
    {
        $resultado = $expo->enviarConResultado($this->token, $this->titulo, $this->cuerpo, $this->data);

        // Expo caido / MessageRateExceeded: se re-encola con espera en vez de
        // perder el aviso. Un token invalido ya quedo revocado y nunca se
        // reintenta (no seguir enviando a dispositivos muertos).
        if ($resultado === ExpoPushService::RESULTADO_REINTENTAR && $this->attempts() < $this->tries) {
            $this->release($this->backoff * $this->attempts());
        }
    }

    /**
     * Si incluso los reintentos fallan, no propagamos la excepcion: el job
     * ya se registro como fallido por la cola (failed_jobs) y
     * ExpoPushService ya dejo su propio log detallado.
     */
    public function failed(?Throwable $exception): void {}
}
