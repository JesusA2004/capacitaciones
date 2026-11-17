<?php

namespace App\Http\Controllers\Capacitacion;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Services\Capacitacion\AulaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aula tipo Classroom de un curso (tablón, trabajo, calificaciones,
 * participantes). La lógica vive en AulaService.
 */
class AulaController extends Controller
{
    public function __construct(private readonly AulaService $aulas) {}

    public function show(Request $request, Curso $curso): Response
    {
        $this->authorize('aula', $curso);

        $usuario = $request->user();

        return Inertia::render('Cursos/Aula', [
            'aula' => $this->aulas->aula($curso, $usuario),
            'puede' => [
                'editar' => $usuario->can('update', $curso),
                'asignar' => $usuario->can('asignaciones.crear'),
                'calificar' => $usuario->can('respuestas.ver'),
            ],
        ]);
    }
}
