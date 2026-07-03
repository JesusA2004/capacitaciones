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
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resolucion de cuestionarios desde "Mi capacitación". Las preguntas que se
 * envian al colaborador nunca incluyen que opcion es correcta ni la
 * explicacion (eso solo se revela, si el cuestionario lo permite, una vez
 * que el intento ya fue calificado).
 *
 * El orden de preguntas/opciones y el tiempo limite se fijan una sola vez al
 * iniciar el intento (IntentoCuestionarioService::iniciarIntento) y se leen
 * de ahi, nunca se recalculan en cada carga de esta pantalla.
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

        if ($intentoActivo) {
            $intentoActivo = $this->intentos->expirarSiVencido($intentoActivo);

            if ($intentoActivo->estado !== EstadoIntentoCuestionario::EnProgreso) {
                $intentosDelUsuario = $intentosDelUsuario->map(
                    fn (IntentoCuestionario $i) => $i->is($intentoActivo) ? $intentoActivo : $i,
                );
                $intentoActivo = null;
            }
        }

        $ultimoFinalizado = $intentosDelUsuario->first(fn (IntentoCuestionario $i) => $i->estado !== EstadoIntentoCuestionario::EnProgreso);

        $intentosRestantes = $cuestionario->intentos_maximos !== null
            ? max(0, $cuestionario->intentos_maximos - $intentosDelUsuario->count())
            : null;

        $preguntasPorId = $cuestionario->preguntas->keyBy('id');
        $preguntas = $this->preguntasParaIntento($cuestionario->preguntas, $preguntasPorId, $intentoActivo);

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
            'segundosRestantes' => $intentoActivo?->fecha_limite ? max(0, $intentoActivo->fecha_limite->getTimestamp() - now()->getTimestamp()) : null,
            'horaServidor' => now()->toIso8601String(),
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
     * @param  Collection<int, Pregunta>  $preguntas
     * @param  Collection<int, Pregunta>  $preguntasPorId
     * @return array<int, array<string, mixed>>
     */
    private function preguntasParaIntento(Collection $preguntas, Collection $preguntasPorId, ?IntentoCuestionario $intentoActivo): array
    {
        if ($intentoActivo === null || $intentoActivo->orden_preguntas === null) {
            // Sin intento activo (pantalla previa a iniciar): el orden no se
            // le muestra al colaborador todavia, se usa el orden natural.
            return $preguntas->map(fn (Pregunta $pregunta) => $this->preguntaParaColaborador($pregunta, null))->values()->all();
        }

        $ordenOpciones = $intentoActivo->orden_opciones ?? [];

        return collect($intentoActivo->orden_preguntas)
            ->map(function (int $preguntaId) use ($preguntasPorId, $ordenOpciones) {
                $pregunta = $preguntasPorId->get($preguntaId);

                return $pregunta ? $this->preguntaParaColaborador($pregunta, $ordenOpciones[$preguntaId] ?? null) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>|null  $ordenOpcionesIds
     * @return array<string, mixed>
     */
    private function preguntaParaColaborador(Pregunta $pregunta, ?array $ordenOpcionesIds): array
    {
        $opciones = $pregunta->opciones->map(fn ($opcion) => ['id' => $opcion->id, 'texto' => $opcion->texto]);

        if ($ordenOpcionesIds !== null) {
            $opciones = collect($ordenOpcionesIds)
                ->map(fn (int $id) => $opciones->firstWhere('id', $id))
                ->filter()
                ->values();
        }

        return [
            'id' => $pregunta->id,
            'enunciado' => $pregunta->enunciado,
            'tipo' => $pregunta->tipo->value,
            'puntos' => $pregunta->pivot->puntos ?? $pregunta->puntos,
            'opciones' => $opciones->values()->all(),
            'escala_min' => $pregunta->escala_min,
            'escala_max' => $pregunta->escala_max,
            'escala_etiqueta_min' => $pregunta->escala_etiqueta_min,
            'escala_etiqueta_max' => $pregunta->escala_etiqueta_max,
            'extensiones_permitidas' => $pregunta->extensiones_permitidas,
            'tamano_maximo_mb' => $pregunta->tamano_maximo_mb,
        ];
    }
}
