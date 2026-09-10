<?php

namespace App\Services\MobilePush;

use App\Models\MobileDevice;
use App\Models\User;

/**
 * Alta/baja de dispositivos moviles para push (Expo). El token es unico en
 * toda la tabla: si el mismo telefono vuelve a registrarse (o lo hace con
 * otra cuenta), updateOrCreate() por push_token reasigna el registro en vez
 * de duplicarlo. Ver docs/PUSH_NOTIFICATIONS.md.
 */
class PushTokenService
{
    /**
     * @param  array{token: string, platform: string, device_name?: string|null, app_version?: string|null}  $datos
     */
    public function registrar(User $usuario, array $datos): MobileDevice
    {
        return MobileDevice::query()->updateOrCreate(
            ['push_token' => $datos['token']],
            [
                'user_id' => $usuario->id,
                'platform' => $datos['platform'],
                'device_name' => $datos['device_name'] ?? null,
                'app_version' => $datos['app_version'] ?? null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    public function revocar(User $usuario, string $token): bool
    {
        $dispositivo = MobileDevice::query()
            ->where('user_id', $usuario->id)
            ->where('push_token', $token)
            ->first();

        if ($dispositivo === null) {
            return false;
        }

        $dispositivo->update(['revoked_at' => now()]);

        return true;
    }
}
