<?php

namespace App\Services\MobilePush;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP minimo del API de push de Expo. No lanza excepciones hacia
 * arriba: quien la use (App\Jobs\SendExpoPushJob) decide que hacer con el
 * resultado, pero un fallo aqui nunca debe interrumpir el flujo de negocio
 * que origino la notificacion (aprobar/rechazar/crear). Ver
 * docs/PUSH_NOTIFICATIONS.md.
 */
class ExpoPushService
{
    public function enabled(): bool
    {
        return (bool) config('expo.enabled');
    }

    /**
     * Envia un push a un solo token. Devuelve true si Expo acepto el
     * mensaje (no garantiza entrega al dispositivo, solo que la peticion a
     * Expo fue exitosa).
     *
     * @param  array<string, mixed>  $data  Debe traer al menos 'type' y 'resource_id'.
     */
    public function enviar(string $token, string $titulo, string $cuerpo, array $data = []): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $respuesta = Http::timeout(10)->post(config('expo.endpoint'), [
                'to' => $token,
                'title' => $titulo,
                'body' => $cuerpo,
                'data' => $data,
            ]);

            if ($respuesta->failed()) {
                Log::warning('Expo push: respuesta fallida', [
                    'status' => $respuesta->status(),
                    'body' => $respuesta->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Expo push: excepcion al enviar', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
