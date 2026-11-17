<?php

namespace App\Http\Controllers\Capacitacion;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Services\Capacitacion\PortadaCapacitacionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portada de Capacitación (modo operativo). Delgado: la lógica vive en
 * PortadaCapacitacionService; la autorización en CursoPolicy::portada.
 */
class PortadaCapacitacionController extends Controller
{
    public function __construct(private readonly PortadaCapacitacionService $portada) {}

    public function index(Request $request): Response
    {
        $this->authorize('portada', Curso::class);

        $usuario = $request->user();

        return Inertia::render('Capacitacion/Index', [
            'portada' => Inertia::defer(fn () => $this->portada->portada($usuario)),
            'puede' => [
                'crear_curso' => $usuario->can('cursos.crear'),
                'ver_cursos' => $usuario->can('cursos.ver'),
                'asignar' => $usuario->can('asignaciones.crear'),
                'calificar' => $usuario->can('respuestas.ver'),
                'certificacion' => (bool) config('features.certificacion') && $usuario->can('certificacion', Curso::class),
            ],
        ]);
    }
}
