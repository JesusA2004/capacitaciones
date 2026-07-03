<?php

namespace App\Services\Evaluacion;

use App\Enums\EstadoIntentoCuestionario;
use App\Enums\TipoPregunta;
use App\Models\Cuestionario;
use App\Models\IntentoCuestionario;
use App\Models\Pregunta;
use App\Models\RespuestaCuestionario;
use App\Models\User;
use App\Notifications\CuestionarioCalificadoNotification;
use App\Services\Capacitacion\ProgresoService;
use Illuminate\Support\Facades\DB;

/**
 * Motor de intentos de cuestionario: inicio (respetando intentos_maximos),
 * calificacion automatica de las preguntas de opcion/verdadero-falso al
 * enviar, y calificacion manual de las de respuesta corta (permiso
 * respuestas.calificar). Un intento solo pasa a "calificado" cuando todas
 * sus preguntas tienen una respuesta calificada (automatica o manual); solo
 * entonces se decide si el usuario aprobo y, si aprobo, se completa la
 * leccion via ProgresoService.
 */
class IntentoCuestionarioService
{
    public function __construct(private readonly ProgresoService $progresoService) {}

    /**
     * @throws \RuntimeException si ya se alcanzo el numero maximo de intentos
     */
    public function iniciarIntento(User $usuario, Cuestionario $cuestionario): IntentoCuestionario
    {
        $enProgreso = IntentoCuestionario::query()
            ->where('cuestionario_id', $cuestionario->id)
            ->where('user_id', $usuario->id)
            ->where('estado', EstadoIntentoCuestionario::EnProgreso->value)
            ->first();

        if ($enProgreso) {
            return $enProgreso;
        }

        $intentosRealizados = IntentoCuestionario::query()
            ->where('cuestionario_id', $cuestionario->id)
            ->where('user_id', $usuario->id)
            ->count();

        if ($cuestionario->intentos_maximos !== null && $intentosRealizados >= $cuestionario->intentos_maximos) {
            throw new \RuntimeException('Ya alcanzaste el número máximo de intentos permitidos para este cuestionario.');
        }

        return IntentoCuestionario::create([
            'cuestionario_id' => $cuestionario->id,
            'user_id' => $usuario->id,
            'numero_intento' => $intentosRealizados + 1,
            'estado' => EstadoIntentoCuestionario::EnProgreso->value,
            'iniciado_en' => now(),
        ]);
    }

    /**
     * @param  array<int, array{pregunta_id: int, opcion_pregunta_id?: int|null, opciones_seleccionadas?: array<int, int>|null, respuesta_texto?: string|null}>  $respuestas
     *
     * @throws \RuntimeException si el intento ya fue enviado
     */
    public function enviarIntento(IntentoCuestionario $intento, array $respuestas): IntentoCuestionario
    {
        if ($intento->estado !== EstadoIntentoCuestionario::EnProgreso) {
            throw new \RuntimeException('Este intento ya fue enviado.');
        }

        $preguntas = $intento->cuestionario->preguntas()->with('opciones')->get()->keyBy('id');

        DB::transaction(function () use ($intento, $respuestas, $preguntas) {
            foreach ($respuestas as $respuesta) {
                $pregunta = $preguntas->get($respuesta['pregunta_id']);

                if ($pregunta) {
                    $this->guardarRespuesta($intento, $pregunta, $respuesta);
                }
            }

            $intento->update(['estado' => EstadoIntentoCuestionario::Enviado->value, 'enviado_en' => now()]);
        });

        $this->intentarFinalizar($intento->fresh());

        return $intento->fresh(['respuestas']);
    }

    public function calificarRespuestaManual(RespuestaCuestionario $respuesta, bool $esCorrecta, ?int $puntosObtenidos = null): void
    {
        $respuesta->loadMissing('pregunta');

        $respuesta->update([
            'es_correcta' => $esCorrecta,
            'puntos_obtenidos' => $puntosObtenidos ?? ($esCorrecta ? $respuesta->pregunta->puntos : 0),
        ]);

        $this->intentarFinalizar($respuesta->intento()->firstOrFail());
    }

    /**
     * @param  array{opcion_pregunta_id?: int|null, opciones_seleccionadas?: array<int, int>|null, respuesta_texto?: string|null}  $datos
     */
    private function guardarRespuesta(IntentoCuestionario $intento, Pregunta $pregunta, array $datos): RespuestaCuestionario
    {
        $valores = [
            'opcion_pregunta_id' => $datos['opcion_pregunta_id'] ?? null,
            'opciones_seleccionadas' => $datos['opciones_seleccionadas'] ?? null,
            'respuesta_texto' => $datos['respuesta_texto'] ?? null,
        ];

        if ($pregunta->tipo->seCalificaAutomaticamente()) {
            [$esCorrecta, $puntos] = $this->calificarAutomaticamente($pregunta, $valores);
            $valores['es_correcta'] = $esCorrecta;
            $valores['puntos_obtenidos'] = $puntos;
        }

        return RespuestaCuestionario::updateOrCreate(
            ['intento_cuestionario_id' => $intento->id, 'pregunta_id' => $pregunta->id],
            $valores,
        );
    }

    /**
     * @param  array{opcion_pregunta_id?: int|null, opciones_seleccionadas?: array<int, int>|null, respuesta_texto?: string|null}  $valores
     * @return array{0: bool, 1: int}
     */
    private function calificarAutomaticamente(Pregunta $pregunta, array $valores): array
    {
        $correctas = $pregunta->opciones->where('es_correcta', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

        $esCorrecta = match ($pregunta->tipo) {
            TipoPregunta::OpcionUnica, TipoPregunta::VerdaderoFalso => $correctas->contains((int) ($valores['opcion_pregunta_id'] ?? 0)),
            TipoPregunta::OpcionMultiple => collect($valores['opciones_seleccionadas'] ?? [])
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all() === $correctas->all(),
            default => false,
        };

        return [$esCorrecta, $esCorrecta ? $pregunta->puntos : 0];
    }

    private function intentarFinalizar(IntentoCuestionario $intento): void
    {
        if ($intento->estado === EstadoIntentoCuestionario::Calificado) {
            return;
        }

        $intento->loadMissing(['respuestas', 'cuestionario.preguntas', 'cuestionario.leccion', 'usuario']);

        $totalPreguntas = $intento->cuestionario->preguntas->count();
        $respuestasCalificadas = $intento->respuestas->whereNotNull('es_correcta');

        if ($respuestasCalificadas->count() < $totalPreguntas) {
            return;
        }

        $puntosObtenidos = (int) $respuestasCalificadas->sum('puntos_obtenidos');
        $puntosPosibles = (int) $intento->cuestionario->preguntas->sum(
            fn (Pregunta $pregunta) => $pregunta->pivot->puntos ?? $pregunta->puntos,
        );

        $calificacion = $puntosPosibles > 0 ? (int) round(($puntosObtenidos / $puntosPosibles) * 100) : 0;
        $aprobado = $calificacion >= $intento->cuestionario->calificacion_minima;

        $intento->update([
            'estado' => EstadoIntentoCuestionario::Calificado->value,
            'calificado_en' => now(),
            'calificacion' => $calificacion,
            'aprobado' => $aprobado,
        ]);

        if ($aprobado) {
            try {
                $this->progresoService->completarLeccion($intento->usuario, $intento->cuestionario->leccion);
            } catch (\RuntimeException) {
                // La leccion se bloqueo por requisitos entre el inicio del intento
                // y su calificacion (caso raro); el intento se conserva como aprobado.
            }
        }

        $intento->usuario->notify(new CuestionarioCalificadoNotification($intento));
    }
}
