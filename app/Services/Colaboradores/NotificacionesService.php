<?php

namespace App\Services\Colaboradores;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Collection;

/**
 * Notificaciones de un colaborador (tabla `notifications` nativa de
 * Laravel). Usado por el layout web (App\Http\Controllers\NotificacionController)
 * y por la API móvil (Api\V1\NotificacionController) — misma lógica.
 */
class NotificacionesService
{
    /**
     * @return array{no_leidas: int, recientes: Collection<int, array{id: mixed, tipo: mixed, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}>}
     */
    public function resumen(User $usuario, int $limite = 10): array
    {
        return [
            'no_leidas' => $usuario->unreadNotifications()->count(),
            'recientes' => $this->transformar($usuario->notifications()->latest()->limit($limite)->get()),
        ];
    }

    /**
     * @return Collection<int, array{id: mixed, tipo: mixed, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}>
     */
    public function listar(User $usuario, int $limite = 30): Collection
    {
        return $this->transformar($usuario->notifications()->latest()->limit($limite)->get());
    }

    /**
     * @param  DatabaseNotificationCollection<int, DatabaseNotification>  $notificaciones
     * @return Collection<int, array{id: mixed, tipo: mixed, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}>
     */
    private function transformar($notificaciones): Collection
    {
        return $notificaciones->map($this->aArray(...));
    }

    /**
     * @return array{id: mixed, tipo: mixed, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}
     */
    private function aArray(DatabaseNotification $notificacion): array
    {
        return [
            'id' => $notificacion->id,
            'tipo' => $notificacion->data['tipo'] ?? null,
            'titulo' => $notificacion->data['titulo'] ?? '',
            'mensaje' => $notificacion->data['mensaje'] ?? '',
            'url' => $notificacion->data['url'] ?? null,
            'leida' => $notificacion->read_at !== null,
            // Cadena ya formateada ("hace 2 horas"): el bell del layout web
            // la muestra tal cual (ver resources/js/components/NotificationBell.vue).
            'creada_en' => $notificacion->created_at?->diffForHumans(),
            'creada_en_iso' => $notificacion->created_at?->toIso8601String(),
        ];
    }

    public function marcarLeida(User $usuario, string $notificacionId): void
    {
        $usuario->notifications()->whereKey($notificacionId)->firstOrFail()->markAsRead();
    }

    public function marcarTodasLeidas(User $usuario): void
    {
        $usuario->unreadNotifications->markAsRead();
    }
}
