<?php

namespace App\Services\MobilePush;

use App\Models\MobileDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP minimo del API de push de Expo. No lanza excepciones hacia
 * arriba: quien la use (App\Jobs\SendExpoPushJob) decide que hacer con el
 * resultado, pero un fallo aqui nunca debe interrumpir el flujo de negocio
 * que origino la notificacion (aprobar/rechazar/crear). Ver
 * docs/PUSH_NOTIFICATIONS.md.
 *
 * Errores del ticket de Expo (docs.expo.dev, "sending notifications",
 * individual errors):
 * - DeviceNotRegistered: el token murio (app desinstalada, token rotado) →
 *   se revoca el dispositivo para no seguir enviandole eternamente.
 * - MessageRateExceeded: demasiados envios al mismo dispositivo → reintento
 *   con espera (el job se re-encola).
 * - InvalidCredentials: credenciales FCM/APNs del proyecto EAS mal
 *   configuradas → error de configuracion, no del token (no se revoca).
 * - MessageTooBig: payload > 4 KiB → error del emisor (no se reintenta).
 */
class ExpoPushService
{
    public const RESULTADO_ENVIADO = 'enviado';

    public const RESULTADO_DESHABILITADO = 'deshabilitado';

    public const RESULTADO_TOKEN_INVALIDO = 'token_invalido';

    public const RESULTADO_REINTENTAR = 'reintentar';

    public const RESULTADO_ERROR = 'error';

    /**
     * Tipos que piden una accion concreta de la persona: canal Android de
     * prioridad alta (heads-up). El resto usa el canal general (importancia
     * normal). Los canales los crea la app (src/services/pushNotifications.ts).
     *
     * @var list<string>
     */
    private const TIPOS_ACCION = [
        'documento_firma_pendiente', 'visto_bueno_pendiente', 'evaluacion_pendiente',
        'evaluacion_devuelta', 'evaluacion_capturada', 'contrato_por_vencer', 'expediente_incompleto',
        'rh_solicitud', 'rh_vacaciones', 'rh_documento', 'rh_incorporacion',
    ];

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
        return $this->enviarConResultado($token, $titulo, $cuerpo, $data) === self::RESULTADO_ENVIADO;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enviarConResultado(string $token, string $titulo, string $cuerpo, array $data = []): string
    {
        if (! $this->enabled()) {
            return self::RESULTADO_DESHABILITADO;
        }

        try {
            $respuesta = Http::timeout(10)->acceptJson()->post(config('expo.endpoint'), $this->mensaje($token, $titulo, $cuerpo, $data));

            if ($respuesta->status() === 429 || $respuesta->serverError()) {
                Log::warning('Expo push: servicio no disponible, se reintentara', ['status' => $respuesta->status()]);

                return self::RESULTADO_REINTENTAR;
            }

            if ($respuesta->failed()) {
                Log::warning('Expo push: respuesta fallida', [
                    'status' => $respuesta->status(),
                    'body' => $respuesta->body(),
                ]);

                return self::RESULTADO_ERROR;
            }

            return $this->interpretarTicket($token, $respuesta->json('data'));
        } catch (\Throwable $e) {
            Log::warning('Expo push: excepcion al enviar', ['message' => $e->getMessage()]);

            return self::RESULTADO_REINTENTAR;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mensaje(string $token, string $titulo, string $cuerpo, array $data): array
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : null;
        $esAccion = in_array($type, self::TIPOS_ACCION, true);
        $canal = $esAccion ? config('expo.canal_acciones') : config('expo.canal_general');

        return array_filter([
            'to' => $token,
            'title' => $titulo,
            'body' => $cuerpo,
            'data' => $data,
            'sound' => 'default',
            'priority' => $esAccion ? 'high' : 'default',
            'channelId' => is_string($canal) && $canal !== '' ? $canal : null,
        ], fn ($valor) => $valor !== null);
    }

    /**
     * Un envio a un solo token regresa un solo ticket (objeto); se acepta
     * tambien la forma de arreglo por si el endpoint la devuelve asi.
     */
    private function interpretarTicket(string $token, mixed $ticket): string
    {
        if (is_array($ticket) && array_is_list($ticket)) {
            $ticket = $ticket[0] ?? null;
        }

        if (! is_array($ticket) || ($ticket['status'] ?? null) === 'ok') {
            return self::RESULTADO_ENVIADO;
        }

        $error = $ticket['details']['error'] ?? null;

        switch ($error) {
            case 'DeviceNotRegistered':
                $revocados = MobileDevice::query()->where('push_token', $token)->activos()->update(['revoked_at' => now()]);
                Log::info('Expo push: token no registrado, dispositivo revocado', ['revocados' => $revocados]);

                return self::RESULTADO_TOKEN_INVALIDO;

            case 'MessageRateExceeded':
                Log::warning('Expo push: limite de envio por dispositivo excedido, se reintentara');

                return self::RESULTADO_REINTENTAR;

            case 'InvalidCredentials':
                Log::error('Expo push: credenciales FCM/APNs invalidas en el proyecto EAS. Revisar credenciales del proyecto.');

                return self::RESULTADO_ERROR;

            case 'MessageTooBig':
                Log::error('Expo push: el mensaje excede 4 KiB. Reducir titulo/cuerpo/data.', ['type' => $ticket['details']['type'] ?? null]);

                return self::RESULTADO_ERROR;

            default:
                Log::warning('Expo push: ticket con error', ['error' => $error, 'message' => $ticket['message'] ?? null]);

                return self::RESULTADO_ERROR;
        }
    }
}
