<?php

namespace App\Support;

/**
 * Mensajes genéricos del framework (en inglés) que llegan tal cual a la app
 * móvil / a las peticiones JSON: se traducen al español. Un mensaje PROPIO
 * (Response::deny('…'), abort(409, '…'), etc.) nunca se toca. El 403 de la
 * API lo resuelve aparte bootstrap/app.php (mensaje de permisos/alcance).
 */
class MensajesErrorApi
{
    /** @var array<string, string> */
    private const GENERICOS = [
        'This action is unauthorized.' => 'No tienes permiso para realizar esta acción.',
        'Unauthenticated.' => 'Tu sesión expiró o no has iniciado sesión. Vuelve a entrar.',
        'Unauthorized' => 'Tu sesión expiró o no has iniciado sesión. Vuelve a entrar.',
        'Forbidden' => 'No tienes permiso para realizar esta acción.',
        'Not Found' => 'No encontramos lo que buscas: puede que ya no exista o que la dirección sea incorrecta.',
        'Too Many Attempts.' => 'Hiciste demasiados intentos seguidos. Espera un momento e inténtalo de nuevo.',
        'Too Many Requests' => 'Hiciste demasiados intentos seguidos. Espera un momento e inténtalo de nuevo.',
        'Method Not Allowed' => 'Esta acción no está disponible por este medio.',
        'Page Expired' => 'Tu sesión expiró. Intenta de nuevo.',
        'CSRF token mismatch.' => 'Tu sesión expiró. Intenta de nuevo.',
        'Server Error' => 'Ocurrió un error inesperado. Inténtalo de nuevo y, si continúa, avísalo a Sistemas.',
        'Service Unavailable' => 'El sistema está en mantenimiento. Inténtalo de nuevo en unos minutos.',
        'The given data was invalid.' => 'Revisa los datos capturados.',
    ];

    /**
     * Texto en español para un mensaje genérico; null si el mensaje es
     * propio (no se reemplaza).
     */
    public static function traducir(int $estado, string $mensaje): ?string
    {
        $mensaje = trim($mensaje);

        if (isset(self::GENERICOS[$mensaje])) {
            return self::GENERICOS[$mensaje];
        }

        // Ruta o registro inexistente: nunca se expone el nombre del modelo
        // ni la ruta interna («No query results for model [App\Models\…]»).
        if ($estado === 404 && ($mensaje === '' || str_starts_with($mensaje, 'No query results for model') || str_starts_with($mensaje, 'The route '))) {
            return self::GENERICOS['Not Found'];
        }

        if ($mensaje === '') {
            return match ($estado) {
                401 => self::GENERICOS['Unauthenticated.'],
                403 => self::GENERICOS['Forbidden'],
                405 => self::GENERICOS['Method Not Allowed'],
                419 => self::GENERICOS['Page Expired'],
                429 => self::GENERICOS['Too Many Attempts.'],
                500 => self::GENERICOS['Server Error'],
                503 => self::GENERICOS['Service Unavailable'],
                default => null,
            };
        }

        return null;
    }
}
