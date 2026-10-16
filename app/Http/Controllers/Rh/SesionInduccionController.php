<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\SesionInduccion;
use App\Services\Onboarding\SesionInduccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sesiones de presentación de la Inducción Mr. Lana (web). RH elige a
 * quién se la presenta ANTES de iniciar, presenta, y al terminar «Envía la
 * evaluación». La regla (permiso, alcance, idempotencia) vive en
 * SesionInduccionService, el mismo que usa la API.
 */
class SesionInduccionController extends Controller
{
    public function __construct(private readonly SesionInduccionService $sesiones) {}

    public function colaboradores(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sesiones->buscarColaboradores($request->user(), $request->string('q')->toString())]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'colaborador_ids' => ['required', 'array', 'min:1', 'max:60'],
            'colaborador_ids.*' => ['required', 'integer'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'colaborador_ids.required' => 'Elige al menos a un colaborador al que le presentarás la inducción.',
            'colaborador_ids.min' => 'Elige al menos a un colaborador al que le presentarás la inducción.',
        ]);

        $sesion = $this->sesiones->iniciar($request->user(), $datos['colaborador_ids'], $datos['notas'] ?? null);

        return to_route('induccion.presentacion')->with('toast', [
            'type' => 'success',
            'message' => sprintf('Sesión iniciada con %d colaborador(es). Al terminar, presiona «Enviar evaluación».', $sesion->asistentes()->count()),
        ]);
    }

    public function enviar(Request $request, SesionInduccion $sesion): RedirectResponse
    {
        $sesion = $this->sesiones->enviarEvaluacion($sesion, $request->user());

        return to_route('induccion.presentacion')->with('toast', $this->sesiones->mensajeEnvio($sesion));
    }

    public function cancelar(Request $request, SesionInduccion $sesion): RedirectResponse
    {
        $this->sesiones->cancelar($sesion, $request->user());

        return to_route('induccion.presentacion')->with('toast', ['type' => 'success', 'message' => 'Sesión cancelada: no se envió ninguna evaluación.']);
    }
}
