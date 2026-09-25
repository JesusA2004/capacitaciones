<?php

namespace App\Services\Colaboradores;

use App\Models\User;
use App\Services\Notificaciones\DestinoNotificacionService;
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
    public function __construct(private readonly DestinoNotificacionService $destinos) {}

    /**
     * Emoji + color por `tipo` de notificación (mismo catálogo para web y
     * API móvil, ver docs/API_MOVIL.md): un vistazo visual rápido de qué
     * tipo de aviso es sin tener que leer el título completo. `color` es
     * una paleta cerrada (success/info/warning/danger/neutral) para que
     * cada cliente (web Tailwind, app móvil) la traduzca a sus propios
     * tokens sin depender de un valor hexadecimal fijo aquí.
     *
     * @var array<string, array{emoji: string, color: string}>
     */
    public const ESTILOS = [
        'incorporacion' => ['emoji' => '🎉', 'color' => 'success'],
        'rh_incorporacion' => ['emoji' => '📋', 'color' => 'info'],
        'documento' => ['emoji' => '📄', 'color' => 'warning'],
        'rh_documento' => ['emoji' => '📄', 'color' => 'warning'],
        'vacaciones' => ['emoji' => '🏖️', 'color' => 'info'],
        'rh_vacaciones' => ['emoji' => '🏖️', 'color' => 'info'],
        'solicitud' => ['emoji' => '📝', 'color' => 'info'],
        'rh_solicitud' => ['emoji' => '📝', 'color' => 'info'],
        'baja' => ['emoji' => '⚠️', 'color' => 'danger'],
        'cumpleanos' => ['emoji' => '🎂', 'color' => 'celebracion'],
        'rh_cumpleanos' => ['emoji' => '🎂', 'color' => 'celebracion'],
        'cumpleanos_muro' => ['emoji' => '🎉', 'color' => 'celebracion'],
        // Celebraciones (docs/CELEBRACIONES.md).
        'aniversario_laboral' => ['emoji' => '🏅', 'color' => 'celebracion'],
        'cumpleanos_general' => ['emoji' => '🎂', 'color' => 'celebracion'],
        'aniversario_general' => ['emoji' => '🏅', 'color' => 'celebracion'],
        'celebracion_mensaje' => ['emoji' => '💌', 'color' => 'celebracion'],
        'sesion_programada' => ['emoji' => '📅', 'color' => 'info'],
        'sesion_proxima' => ['emoji' => '⏰', 'color' => 'warning'],
        'fecha_limite_proxima' => ['emoji' => '⏰', 'color' => 'warning'],
        'asignacion_creada' => ['emoji' => '📚', 'color' => 'info'],
        'cuestionario_calificado' => ['emoji' => '✅', 'color' => 'success'],
        'calificaciones_pendientes' => ['emoji' => '📝', 'color' => 'warning'],
        'actividad_calificada' => ['emoji' => '✅', 'color' => 'success'],
    ];

    public const ESTILO_DEFAULT = ['emoji' => '🔔', 'color' => 'neutral'];

    /**
     * Emoji para un `tipo`/`type` de notificación — usado también por
     * App\Services\MobilePush\PushNotifier para anteponerlo al título del
     * push nativo, mismo catálogo que la campana web y la API móvil.
     */
    public static function emojiPara(?string $tipo): string
    {
        return self::ESTILOS[(string) $tipo]['emoji'] ?? self::ESTILO_DEFAULT['emoji'];
    }

    /**
     * Color hexadecimal de referencia para un `tipo`/`type` — para clientes
     * (app móvil) que quieran pintar un acento sin tener que traducir el
     * nombre de color cerrado (success/info/...) ellos mismos.
     */
    public static function colorHexPara(?string $tipo): string
    {
        $color = self::ESTILOS[(string) $tipo]['color'] ?? self::ESTILO_DEFAULT['color'];

        return match ($color) {
            'success' => '#22c55e',
            'info' => '#0ea5e9',
            'warning' => '#f59e0b',
            'danger' => '#ef4444',
            'celebracion' => '#ec4899',
            default => '#6b7280',
        };
    }

    /**
     * @return array{no_leidas: int, recientes: Collection<int, array{id: mixed, tipo: mixed, emoji: string, color: string, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}>}
     */
    public function resumen(User $usuario, int $limite = 10): array
    {
        return [
            'no_leidas' => $usuario->unreadNotifications()->count(),
            'recientes' => $this->transformar($usuario->notifications()->latest()->limit($limite)->get()),
        ];
    }

    /**
     * @return Collection<int, array{id: mixed, tipo: mixed, emoji: string, color: string, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}>
     */
    public function listar(User $usuario, int $limite = 30): Collection
    {
        return $this->transformar($usuario->notifications()->latest()->limit($limite)->get());
    }

    /**
     * @param  DatabaseNotificationCollection<int, DatabaseNotification>  $notificaciones
     * @return Collection<int, array{id: mixed, tipo: mixed, emoji: string, color: string, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}>
     */
    private function transformar($notificaciones): Collection
    {
        return $notificaciones->map($this->aArray(...));
    }

    /**
     * @return array{id: mixed, tipo: mixed, emoji: string, color: string, titulo: mixed, mensaje: mixed, url: mixed, leida: bool, creada_en: mixed, creada_en_iso: mixed}
     */
    private function aArray(DatabaseNotification $notificacion): array
    {
        $tipo = $notificacion->data['tipo'] ?? null;
        $estilo = self::ESTILOS[(string) $tipo] ?? self::ESTILO_DEFAULT;

        return [
            'id' => $notificacion->id,
            'tipo' => $tipo,
            'emoji' => $estilo['emoji'],
            'color' => $estilo['color'],
            'titulo' => $notificacion->data['titulo'] ?? '',
            'mensaje' => $notificacion->data['mensaje'] ?? '',
            'url' => $notificacion->data['url'] ?? null,
            'leida' => $notificacion->read_at !== null,
            // Cadena ya formateada ("hace 2 horas"): el bell del layout web
            // la muestra tal cual (ver resources/js/components/NotificationBell.vue).
            'creada_en' => $notificacion->created_at?->diffForHumans(),
            'creada_en_iso' => $notificacion->created_at?->toIso8601String(),
            // Campos para la app movil (docs/API_MOVIL.md): data.type/resource_id
            // en vez de una URL web, para que la app navegue nativamente.
            'created_at' => $notificacion->created_at?->toIso8601String(),
            'data' => [
                'type' => $notificacion->data['type'] ?? $notificacion->data['tipo'] ?? null,
                'resource_id' => $notificacion->data['resource_id'] ?? null,
                // Ciclo laboral (PendienteRhNotification): objeto y accion esperada
                // para navegacion determinista en la app; null en avisos antiguos.
                'related_type' => $notificacion->data['related_type'] ?? null,
                'accion' => $notificacion->data['accion'] ?? null,
                // Avisos agregados (rh_cumpleanos) navegan por periodo, no por id.
                'periodo' => $notificacion->data['periodo'] ?? null,
            ],
        ];
    }

    public function marcarLeida(User $usuario, string $notificacionId): void
    {
        $usuario->notifications()->whereKey($notificacionId)->firstOrFail()->markAsRead();
    }

    /**
     * Abrir una notificación desde la campana/lista (web) o desde la app:
     * la marca como leída (idempotente: abrirla otra vez no cambia nada) y
     * dice a dónde llevar al usuario y si lo que avisaba ya fue atendido,
     * con el estado ACTUAL del recurso (ver DestinoNotificacionService).
     * `no_leidas` va de regreso para que el contador se actualice al
     * instante sin esperar al siguiente sondeo.
     *
     * @return array{url: string|null, atendida: bool|null, estado_recurso: string|null, mensaje_estado: string|null, no_leidas: int}
     */
    public function abrir(User $usuario, string $notificacionId): array
    {
        $notificacion = $usuario->notifications()->whereKey($notificacionId)->firstOrFail();
        $notificacion->markAsRead();

        return [
            ...$this->destinos->resolver($notificacion, $usuario),
            'no_leidas' => $usuario->unreadNotifications()->count(),
        ];
    }

    public function marcarTodasLeidas(User $usuario): void
    {
        $usuario->unreadNotifications->markAsRead();
    }
}
