<?php

namespace App\Services\MobilePush;

use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Alta/baja de dispositivos moviles para push (Expo). El token es unico en
 * toda la tabla: si el mismo telefono vuelve a registrarse (o lo hace con
 * otra cuenta, ej. logout/login de otra persona en el mismo dispositivo),
 * updateOrCreate() por push_token reasigna el registro en vez de
 * duplicarlo. Ver docs/PUSH_NOTIFICATIONS.md.
 */
class PushTokenService
{
    /**
     * @param  array{token: string, platform: string, device_name?: string|null, app_version?: string|null}  $datos
     */
    public function registrar(User $usuario, array $datos): MobileDevice
    {
        $anterior = MobileDevice::query()->where('push_token', $datos['token'])->first();

        // Reasignar el mismo token a otra cuenta es el flujo normal de
        // "cambiar de cuenta en el mismo teléfono" — pero si alguna vez se
        // abusa (alguien más consigue el token literal de otra persona y lo
        // registra desde su propia cuenta), esto deja rastro en el log sin
        // bloquear el caso legítimo (auditoría, sección 39 del encargo).
        if ($anterior !== null && $anterior->user_id !== $usuario->id) {
            Log::warning('push: token reasignado a otra cuenta.', [
                'push_token_hash' => hash('sha256', $datos['token']),
                'usuario_anterior_id' => $anterior->user_id,
                'usuario_nuevo_id' => $usuario->id,
            ]);
        }

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
