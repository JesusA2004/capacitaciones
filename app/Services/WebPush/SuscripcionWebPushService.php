<?php

namespace App\Services\WebPush;

use App\Models\User;
use App\Models\WebPushSuscripcion;
use Illuminate\Support\Str;

/**
 * Alta/baja de la suscripción Web Push del navegador actual. Un endpoint
 * pertenece a un solo usuario: si en el mismo navegador entra otra cuenta,
 * la suscripción se reasigna (igual que PushTokenService con los teléfonos),
 * para que nunca le lleguen a alguien los avisos de la sesión anterior.
 */
class SuscripcionWebPushService
{
    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null}  $datos
     */
    public function registrar(User $usuario, array $datos, ?string $navegador): WebPushSuscripcion
    {
        return WebPushSuscripcion::query()->updateOrCreate(
            ['endpoint_hash' => WebPushSuscripcion::hashDe($datos['endpoint'])],
            [
                'user_id' => $usuario->id,
                'endpoint' => $datos['endpoint'],
                'clave_publica' => $datos['keys']['p256dh'],
                'token_autenticacion' => $datos['keys']['auth'],
                'codificacion' => $datos['contentEncoding'] ?? 'aes128gcm',
                'navegador' => $navegador !== null ? Str::limit($navegador, 117) : null,
            ],
        );
    }

    public function eliminar(User $usuario, string $endpoint): void
    {
        WebPushSuscripcion::query()
            ->where('user_id', $usuario->id)
            ->where('endpoint_hash', WebPushSuscripcion::hashDe($endpoint))
            ->delete();
    }
}
