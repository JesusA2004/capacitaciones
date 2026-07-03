<?php

namespace App\Http\Controllers\MiCapacitacion;

use App\Enums\EstadoIntentoCuestionario;
use App\Http\Controllers\Controller;
use App\Http\Requests\MiCapacitacion\EnviarIntentoCuestionarioRequest;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\Pregunta;
use App\Services\Capacitacion\ProgresoService;
use App\Services\Evaluacion\IntentoCuestionarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resolucion de cuestionarios desde "Mi capacitación". Las preguntas que se
 * envian al colaborador nunca incluyen que opcion es correcta ni la
 * explicacion (eso solo se revela, si el cuestionario lo permite, una vez
 * que el intento ya fue calificado).
 */
class IntentoCuestionarioController extends Controller
{
    public function __construct(
        private readonly IntentoCuestionarioService $intentos,
        private readonly ProgresoService $progreso,
    ) {}

    public function show(Request $request, Leccion $leccion): Response
    {
        $usuario = $request->user();
        $this->progreso->autorizarAccesoLeccion($usuario, $leccion);

        $cuestionario = $leccion->cuestionario()->with('preguntas.opciones')->firstOrFail();

        $intentosDelUsuario = IntentoCuestionario::query()
            ->where('cuestionario_id', $cuestionario->id)
            ->where('user_id', $usuario->id)
            ->orderByDesc('numero_intento')
            ->get();

        $intentoActivo = $intentosDelUsuario->firstWhere('estado', EstadoIntentoCuestionario::EnProgreso);
        $ultimoFinalizado = $intentosDelUsuario->first(fn (IntentoCuestionario $i) => $i->estado !== EstadoIntentoCuestionario::EnProgreso);

        $intentosRestantes = $cuestionario->intentos_maximos !== null
            ? max(0, $cuestionario->intentos_maximos - $intentosDelUsuario->count())
            : null;

        $preguntas = $cuestionario->preguntas->map(fn (Pregunta $pregunta) => $this->preguntaParaColaborador($pregunta))->values();

        if ($cuestionario->aleatorizar_preguntas) {
            $preguntas = $preguntas->shuffle()->values();
        }

        $retroalimentacion = null;

        if ($ultimoFinalizado && $ultimoFinalizado->estado === EstadoIntentoCuestionario::Calificado && $cuestionario->mostrar_retroalimentacion) {
            $retroalimentacion = $ultimoFinalizado->respuestas()->with('pregunta:id,explicacion')->get()
                ->map(fn ($respuesta) => [
                    'pregunta_id' => $respuesta->pregunta_id,
                    'es_correcta' => $respuesta->es_correcta,
                    'explicacion' => $respuesta->pregunta->explicacion,
                ]);
        }

        return Inertia::render('MiCapacitacion/Cuestionario', [
            'leccion' => $leccion,
            'cuestionario' => [
                'id' => $cuestionario->id,
                'titulo' => $cuestionario->titulo,
                'instrucciones' => $cuestionario->instrucciones,
                'tiempo_limite_minutos' => $cuestionario->tiempo_limite_minutos,
            ],
            'preguntas' => $preguntas,
            'intentoActivoId' => $intentoActivo?->id,
            'intentosRestantes' => $intentosRestantes,
            'ultimoResultado' => $ultimoFinalizado ? [
                'estado' => $ultimoFinalizado->estado->value,
                'calificacion' => $ultimoFinalizado->calificacion,
                'aprobado' => $ultimoFinalizado->aprobado,
            ] : null,
            'retroalimentacion' => $retroalimentacion,
        ]);
    }

    public function iniciar(Request $request, Leccion $leccion): RedirectResponse
    {
        $usuario = $request->user();
        $this->progreso->autorizarAccesoLeccion($usuario, $leccion);

        $cuestionario = $leccion->cuestionario()->firstOrFail();

        try {
            $this->intentos->iniciarIntento($usuario, $cuestionario);
        } catch (\RuntimeException $excepcion) {
            return back()->with('toast', ['type' => 'error', 'message' => $excepcion->getMessage()]);
        }

        return back();
    }

    public function enviar(EnviarIntentoCuestionarioRequest $request, IntentoCuestionario $intento): RedirectResponse
    {
        try {
            $this->intentos->enviarIntento($intento, $request->validated('respuestas', []));
        } catch (\RuntimeException $excepcion) {
            return back()->with('toast', ['type' => 'error', 'message' => $excepcion->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Cuestionario enviado correctamente.']);
    }

    /**
     * @return array{id: int, enunciado: string, tipo: string, puntos: int, opciones: array<int, array{id: int, texto: string}>}
     */
    private function preguntaParaColaborador(Pregunta $pregunta): array
    {
        return [
            'id' => $pregunta->id,
            'enunciado' => $pregunta->enunciado,
            'tipo' => $pregunta->tipo->value,
            'puntos' => $pregunta->pivot->puntos ?? $pregunta->puntos,
            'opciones' => $pregunta->opciones->map(fn ($opcion) => ['id' => $opcion->id, 'texto' => $opcion->texto])->values()->all(),
        ];
    }
}
