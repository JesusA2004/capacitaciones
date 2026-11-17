<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\EvaluacionesTecnicas\EvaluacionTecnicaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mis evaluaciones técnicas» (Mi espacio): las «Seguimiento N» que el
 * colaborador contesta como examen, sus resultados y — si es jefe directo —
 * las de su equipo que le toca evaluar. Siempre la cuenta en sesión.
 */
class MisEvaluacionesTecnicasController extends Controller
{
    public function __construct(private readonly EvaluacionTecnicaService $evaluaciones) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Portal/EvaluacionesTecnicas', $this->evaluaciones->misEvaluaciones($request->user()));
    }
}
