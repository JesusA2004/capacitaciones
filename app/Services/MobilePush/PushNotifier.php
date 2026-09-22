<?php

namespace App\Services\MobilePush;

use App\Jobs\SendExpoPushJob;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Colaboradores\NotificacionesService;
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
        $type = $data['type'] ?? null;
        $type = is_string($type) ? $type : null;

        $tituloConEmoji = $this->conEmoji($titulo, $type);
        $data['color'] ??= NotificacionesService::colorHexPara($type);
        // Destinatario (id interno, no PII): si el teléfono cambió de cuenta y
        // llega/se toca un push de la sesión anterior, la app no lo abre.
        $data['user_id'] ??= $usuario->id;

        foreach ($this->tokensActivos($usuario) as $token) {
            SendExpoPushJob::dispatch($token, $tituloConEmoji, $cuerpo, $data);
        }
    }

    /**
     * Antepone el emoji del tipo de notificación al título del push nativo
     * (mismo catálogo que la campana web y la API — ver
     * App\Services\Colaboradores\NotificacionesService::ESTILOS), sin
     * duplicarlo si el llamador ya lo incluyó a mano en el título.
     */
    private function conEmoji(string $titulo, ?string $type): string
    {
        $emoji = NotificacionesService::emojiPara($type);

        return str_starts_with($titulo, $emoji) ? $titulo : "{$emoji} {$titulo}";
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
