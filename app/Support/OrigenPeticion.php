<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * De dónde vino una acción: «app» (API móvil /api/*), «web» (portal) o
 * «sistema» (comando programado, cola, consola). Lo usan el historial de
 * solicitudes y la auditoría para guardar origen, IP y user agent sin que
 * cada servicio lea la petición por su cuenta.
 */
final class OrigenPeticion
{
    public const APP = 'app';

    public const WEB = 'web';

    public const SISTEMA = 'sistema';

    public static function actual(?Request $request = null): string
    {
        $request ??= self::peticion();

        if ($request === null) {
            return self::SISTEMA;
        }

        return $request->is('api/*') ? self::APP : self::WEB;
    }

    public static function etiqueta(?string $origen): ?string
    {
        return match ($origen) {
            self::APP => 'App móvil',
            self::WEB => 'Portal web',
            self::SISTEMA => 'Sistema',
            default => null,
        };
    }

    /**
     * @return array{origen: string, ip: string|null, user_agent: string|null}
     */
    public static function contexto(?Request $request = null): array
    {
        $request ??= self::peticion();

        return [
            'origen' => self::actual($request),
            'ip' => $request?->ip(),
            'user_agent' => $request !== null && $request->userAgent() !== null ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ];
    }

    private static function peticion(): ?Request
    {
        $request = app()->bound('request') ? app('request') : null;

        // En consola/colas Laravel registra una petición vacía: no es una
        // petición real si no tiene ruta.
        return $request instanceof Request && $request->route() !== null ? $request : null;
    }
}
