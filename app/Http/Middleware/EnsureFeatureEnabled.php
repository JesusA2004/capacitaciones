<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege un grupo de rutas detras de una bandera de config/features.php.
 * No es un mecanismo de borrado ni de mantenimiento: si la bandera esta
 * apagada, las peticiones de lectura (GET) reciben la pantalla "Proximamente"
 * en la misma URL y las de escritura un 403, sin afectar datos ni rutas.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (config("features.{$feature}")) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            return Inertia::render('Capacitacion/Proximamente')->toResponse($request);
        }

        abort(403, 'Este modulo esta desactivado temporalmente.');
    }
}
