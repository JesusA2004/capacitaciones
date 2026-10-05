<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contraseña temporal (migración inicial, «Establecer contraseña» de
 * Administración): mientras `users.debe_cambiar_contrasena` sea verdadero,
 * la persona solo puede cambiarla o cerrar sesión — ni la web ni la API
 * dejan navegar al resto del sistema (docs/AUTENTICACION.md).
 */
class ExigirCambioContrasena
{
    /** Rutas permitidas mientras el cambio está pendiente. */
    private const PERMITIDAS = [
        'contrasena-temporal.*',
        'logout',
        'api.v1.logout',
        'api.v1.me',
        'api.v1.cambiar-contrasena',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null || ! $usuario->debe_cambiar_contrasena || $request->routeIs(...self::PERMITIDAS)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña temporal antes de continuar.',
                'codigo' => 'cambio_contrasena_requerido',
            ], 403);
        }

        return redirect()->route('contrasena-temporal.edit');
    }
}
