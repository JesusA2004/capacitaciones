<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\OnboardingAvance;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mi proceso" (modo colaborador): dónde estoy en mi ciclo laboral, qué me
 * falta y mi inducción. Siempre lee la persona del usuario autenticado —
 * nunca la de otro —; los datos y la calificación salen de los mismos
 * services que usa la app (CicloLaboralService::miProceso, OnboardingService).
 */
class MiProcesoController extends Controller
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly OnboardingService $onboarding,
    ) {}

    public function show(Request $request): Response
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403);

        return Inertia::render('Portal/MiProceso', $this->ciclo->misPendientes($colaborador, $usuario));
    }

    public function evaluacion(Request $request, OnboardingAvance $avance): RedirectResponse
    {
        $datos = $request->validate([
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['required', 'integer', 'min:0'],
        ]);

        $intento = $this->onboarding->presentarEvaluacion($avance, $request->user(), $datos['respuestas']);

        return back()->with('toast', $intento->aprobado
            ? ['type' => 'success', 'message' => sprintf('¡Aprobaste con %s!', number_format((float) $intento->calificacion, 1))]
            : ['type' => 'warning', 'message' => sprintf('Obtuviste %s: RH te dará retroalimentación para reevaluar.', number_format((float) $intento->calificacion, 1))]);
    }
}
