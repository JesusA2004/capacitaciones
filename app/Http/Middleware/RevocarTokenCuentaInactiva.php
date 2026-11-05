<?php

namespace App\Http\Middleware;

use App\Services\Autenticacion\AutenticacionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivalente de EnsureCuentaActiva para la app (token Sanctum): si la
 * cuenta ya no puede iniciar sesión (acceso revocado, colaborador inactivo o
 * suspendido) el token se borra y la petición recibe 401, aunque el cambio
 * de estatus haya llegado por un camino que no revocó tokens (p. ej. la
 * migración inicial de expedientes marcando una baja). Misma regla que el
 * login (AutenticacionService::puedeIniciarSesion).
 */
class RevocarTokenCuentaInactiva
{
    public function __construct(private readonly AutenticacionService $autenticacion) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return $next($request);
        }

        $usuario = $request->user('sanctum');

        if ($usuario === null) {
            return $next($request);
        }

        // Solo un token real de la base (nunca la sesión web ni el token
        // transitorio de las pruebas): el mismo que mandó la app.
        $token = PersonalAccessToken::findToken($bearer);

        if ($token !== null && $usuario->is($token->tokenable) && ! $this->autenticacion->puedeIniciarSesion($usuario)) {
            $token->delete();

            return response()->json(['message' => 'Tu cuenta está desactivada. Contacta a Recursos Humanos.'], 401);
        }

        return $next($request);
    }
}
