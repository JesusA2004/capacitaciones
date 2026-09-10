<?php

namespace App\Services\MobilePush;

use App\Jobs\SendExpoPushJob;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Punto unico para encolar push a un usuario (o varios). Resuelve los
 * dispositivos activos de cada usuario y encola un App\Jobs\SendExpoPushJob
 * por token: nunca se llama a Expo directamente desde un controller o
 * service de negocio. Ver docs/PUSH_NOTIFICATIONS.md.
 *
 * Tipos usados por la app (data.type):
 * - Colaborador: solicitud, documento, vacaciones, incorporacion, notificacion
 * - RH/aprobador: rh_solicitud, rh_documento, rh_vacaciones, rh_incorporacion, rh_pendiente
 */
class PushNotifier
{
    /**
     * @param  int  $resourceId  Id del recurso relacionado (solicitud, documento, vacacion, colaborador...). Nunca PII.
     */
    public function aUsuario(User $usuario, string $type, int $resourceId, string $titulo, string $cuerpo): void
    {
        $this->aUsuarioConDatos($usuario, $titulo, $cuerpo, ['type' => $type, 'resource_id' => $resourceId]);
    }

    /**
     * Variante para avisos que no apuntan a un solo recurso con id entero
     * (p. ej. un resumen "N cumpleaños hoy"): en vez de inventar un
     * resource_id falso (como un timestamp), el llamador arma su propio
     * payload `data` — típicamente con `resource_id: null` y una `route`
     * navegable en su lugar. Ver App\Notifications\Mobile\BirthdayRhReminderNotification.
     *
     * @param  array<string, mixed>  $data  Siempre debe incluir 'type'; nunca PII.
     */
    public function aUsuarioConDatos(User $usuario, string $titulo, string $cuerpo, array $data): void
    {
        foreach ($this->tokensActivos($usuario) as $token) {
            SendExpoPushJob::dispatch($token, $titulo, $cuerpo, $data);
        }
    }

    /**
     * @param  Collection<int, User>|iterable<User>  $usuarios
     */
    public function aUsuarios(iterable $usuarios, string $type, int $resourceId, string $titulo, string $cuerpo): void
    {
        foreach ($usuarios as $usuario) {
            $this->aUsuario($usuario, $type, $resourceId, $titulo, $cuerpo);
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function tokensActivos(User $usuario): Collection
    {
        return MobileDevice::query()
            ->where('user_id', $usuario->id)
            ->activos()
            ->pluck('push_token');
    }
}
