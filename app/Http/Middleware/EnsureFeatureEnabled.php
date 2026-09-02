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
    /**
     * Metadatos de la pantalla "Proximamente" para features sin pantalla
     * dedicada propia (capacitacion conserva su pagina original con el
     * detalle de los modulos ya construidos).
     */
    private const METADATA = [
        'desempeno' => [
            'titulo' => 'Desempeño',
            'descripcion' => 'Evaluación de desempeño de colaboradores. Disponible en la Fase 2 del roadmap.',
            'fase' => 'Fase 2',
        ],
        'nine_box' => [
            'titulo' => 'Nine Box',
            'descripcion' => 'Matriz de talento (desempeño vs. potencial). Disponible en la Fase 2 del roadmap.',
            'fase' => 'Fase 2',
        ],
    ];

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (config("features.{$feature}")) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            if ($feature === 'capacitacion') {
                return Inertia::render('Capacitacion/Proximamente')->toResponse($request);
            }

            $meta = self::METADATA[$feature] ?? [
                'titulo' => ucfirst(str_replace('_', ' ', $feature)),
                'descripcion' => 'Este módulo estará disponible en una fase futura.',
                'fase' => null,
            ];

            return Inertia::render('Proximamente', $meta)->toResponse($request);
        }

        abort(403, 'Este modulo esta desactivado temporalmente.');
    }
}
