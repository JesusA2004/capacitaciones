<?php

namespace App\Http\Middleware;

use App\Enums\EstadoUsuario;
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
 * middleware la cierra en la siguiente petición. `en_incorporacion` sigue
 * pudiendo entrar (mismo criterio que Api\V1\AuthController::login()).
 */
class EnsureCuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $estatus = $usuario?->colaborador?->estatus;

        if ($usuario !== null && (
            $estatus === null
            || in_array($estatus, [EstadoUsuario::Inactivo, EstadoUsuario::Suspendido], true)
            || $usuario->acceso_bloqueado_en !== null
        )) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Tu cuenta está desactivada. Contacta a Recursos Humanos.',
            ]);
        }

        return $next($request);
    }
}
