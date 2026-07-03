<?php

namespace App\Http\Controllers\Cuestionarios;

use App\Enums\EstadoIntentoCuestionario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuestionarios\CalificarRespuestaRequest;
use App\Models\IntentoCuestionario;
use App\Models\RespuestaCuestionario;
use App\Services\Evaluacion\IntentoCuestionarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calificacion manual de las respuestas de tipo "respuesta_corta" que
 * quedaron pendientes tras enviarse un intento (las de opcion/verdadero-falso
 * ya se calificaron solas). Un intento pasa a "calificado" automaticamente en
 * cuanto su ultima respuesta pendiente recibe calificacion.
 */
class CalificacionCuestionarioController extends Controller
{
    public function __construct(private readonly IntentoCuestionarioService $intentos) {}

    public function index(Request $request): Response
    {
        $this->authorize('respuestas.ver');

        $intentos = IntentoCuestionario::query()
            ->where('estado', EstadoIntentoCuestionario::Enviado->value)
            ->with(['usuario:id,name,apellidos', 'cuestionario:id,titulo,leccion_id'])
            ->orderBy('enviado_en')
            ->paginate(15);

        return Inertia::render('Cuestionarios/Calificaciones/Index', [
            'intentos' => $intentos,
        ]);
    }

    public function show(IntentoCuestionario $intento): Response
    {
        $this->authorize('respuestas.ver');

        $intento->load(['usuario:id,name,apellidos', 'cuestionario:id,titulo', 'respuestas.pregunta.opciones']);

        return Inertia::render('Cuestionarios/Calificaciones/Show', [
            'intento' => $intento,
        ]);
    }

    public function calificar(CalificarRespuestaRequest $request, RespuestaCuestionario $respuesta): RedirectResponse
    {
        $this->intentos->calificarRespuestaManual(
            $respuesta,
            $request->boolean('es_correcta'),
            $request->input('puntos_obtenidos'),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Respuesta calificada correctamente.']);
    }
}
