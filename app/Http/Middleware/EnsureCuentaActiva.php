<?php

namespace App\Http\Middleware;

use App\Services\Autenticacion\AutenticacionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso web de un colaborador dado de baja (o suspendido)
 * aunque ya tenga una sesión abierta — la baja por Solicitudes
 * (App\Services\Solicitudes\BajaColaboradorService) revoca tokens Sanctum y
 * dispositivos móviles de inmediato, pero una sesión de cookie de Laravel
 * ya iniciada no se invalida sola con un cambio de `estatus`: este
 * middleware la cierra en la siguiente petición. Usa la MISMA regla que el
 * login (AutenticacionService::puedeIniciarSesion): `en_incorporacion` sigue
 * entrando y una cuenta operativa sin colaborador (super_admin de arranque)
 * tampoco se expulsa.
 */
class EnsureCuentaActiva
{
    public function __construct(private readonly AutenticacionService $autenticacion) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $this->autenticacion->puedeIniciarSesion($usuario)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => 'Tu cuenta está desactivada. Contacta a Recursos Humanos.',
            ]);
        }

        return $next($request);
    }
}
