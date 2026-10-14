<?php

namespace App\Services\Reclutamiento;

use App\Models\Candidato;
use App\Models\MotivoRechazoCandidato;
use App\Models\PreguntaFiltroCandidato;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Preguntas rápidas de requisito indispensable al registrar un candidato
 * (p. ej. «¿Maneja moto?» para Gestor). La respuesta que no cumple una
 * pregunta eliminatoria NO impide registrar al candidato (debe contar en
 * su campaña): CandidatoWorkflowService::registrar() lo cierra al momento
 * como «No viable» con el motivo de catálogo de la pregunta.
 */
class PreguntasFiltroCandidatoService
{
    /** Motivo de catálogo cuando la pregunta no trae uno propio. */
    public const MOTIVO_POR_DEFECTO = 'no_cumple_requisito';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Activas, en orden (opciones del formulario de candidato).
     *
     * @return Collection<int, PreguntaFiltroCandidato>
     */
    public function activas(): Collection
    {
        return PreguntaFiltroCandidato::query()->activas()->orderBy('orden')->orderBy('id')->get();
    }

    /**
     * Revisa que se hayan contestado TODAS las preguntas activas que aplican
     * al puesto y calcula cuáles no se cumplen. No escribe nada (se llama
     * antes de crear al candidato).
     *
     * @param  array<int|string, mixed>  $respuestas  pregunta_id => bool
     * @return array{respuestas: list<array{pregunta: PreguntaFiltroCandidato, respuesta: bool, cumple: bool}>, incumplidas: list<array{pregunta: PreguntaFiltroCandidato, respuesta: bool}>}
     */
    public function evaluar(?int $puestoId, array $respuestas): array
    {
        $evaluadas = [];
        $incumplidas = [];
        $faltantes = [];

        foreach ($this->activas() as $pregunta) {
            if (! $pregunta->aplicaA($puestoId)) {
                continue;
            }

            $valor = $respuestas[$pregunta->id] ?? $respuestas[(string) $pregunta->id] ?? null;
            $respuesta = $valor === null || $valor === '' ? null : filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($respuesta === null) {
                $faltantes[sprintf('respuestas_filtro.%d', $pregunta->id)] = sprintf('Responde: %s', $pregunta->pregunta);

                continue;
            }

            $cumple = $pregunta->cumple($respuesta);
            $evaluadas[] = ['pregunta' => $pregunta, 'respuesta' => $respuesta, 'cumple' => $cumple];

            if (! $cumple && $pregunta->eliminatoria) {
                $incumplidas[] = ['pregunta' => $pregunta, 'respuesta' => $respuesta];
            }
        }

        if ($faltantes !== []) {
            throw ValidationException::withMessages($faltantes);
        }

        return ['respuestas' => $evaluadas, 'incumplidas' => $incumplidas];
    }

    /**
     * Respuestas que cumplen todas las preguntas activas (seeders de demo:
     * candidatos que sí deben seguir en el pipeline).
     *
     * @return array<int, bool>
     */
    public function respuestasQueCumplen(): array
    {
        return $this->activas()->mapWithKeys(fn (PreguntaFiltroCandidato $p): array => [$p->id => $p->respuesta_requerida])->all();
    }

    /**
     * @param  list<array{pregunta: PreguntaFiltroCandidato, respuesta: bool, cumple: bool}>  $respuestas
     */
    public function guardarRespuestas(Candidato $candidato, array $respuestas): void
    {
        foreach ($respuestas as $fila) {
            $candidato->respuestasFiltro()->create([
                'pregunta_filtro_candidato_id' => $fila['pregunta']->id,
                'pregunta' => $fila['pregunta']->pregunta,
                'respuesta' => $fila['respuesta'],
                'cumple' => $fila['cumple'],
                'eliminatoria' => $fila['pregunta']->eliminatoria,
            ]);
        }
    }

    /**
     * Motivo de catálogo con que se cierra a quien no cumple la pregunta:
     * el propio de la pregunta si sigue activo; si no, el genérico.
     */
    public function motivoDe(PreguntaFiltroCandidato $pregunta): ?int
    {
        $ids = array_filter([$pregunta->motivo_rechazo_id]);
        $activo = $ids !== [] ? MotivoRechazoCandidato::query()->activos()->whereKey($ids)->value('id') : null;

        return $activo !== null
            ? (int) $activo
            : $this->idMotivoActivo(self::MOTIVO_POR_DEFECTO);
    }

    public function idMotivoActivo(string $clave): ?int
    {
        $id = MotivoRechazoCandidato::query()->activos()->where('clave', $clave)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Alta/edición desde Configuración → Parámetros de RH. Nunca se borra:
     * una pregunta ya contestada se desactiva.
     *
     * @param  array<string, mixed>  $datos  Validado por GuardarPreguntaFiltroCandidatoRequest.
     */
    public function guardar(array $datos, User $actor, ?PreguntaFiltroCandidato $pregunta = null): PreguntaFiltroCandidato
    {
        $atributos = [
            'pregunta' => (string) $datos['pregunta'],
            'puestos' => array_values(array_map(fn (mixed $id): int => (int) $id, (array) ($datos['puestos'] ?? []))),
            'respuesta_requerida' => (bool) ($datos['respuesta_requerida'] ?? true),
            'eliminatoria' => (bool) ($datos['eliminatoria'] ?? true),
            'motivo_rechazo_id' => isset($datos['motivo_rechazo_id']) && is_numeric($datos['motivo_rechazo_id']) ? (int) $datos['motivo_rechazo_id'] : null,
            'activo' => (bool) ($datos['activo'] ?? true),
            'orden' => isset($datos['orden']) && is_numeric($datos['orden']) ? (int) $datos['orden'] : 0,
        ];

        if ($pregunta === null) {
            $pregunta = PreguntaFiltroCandidato::query()->create([
                ...$atributos,
                'clave' => $this->claveUnica((string) $datos['pregunta']),
                'orden' => $atributos['orden'] ?: (int) PreguntaFiltroCandidato::query()->max('orden') + 1,
            ]);
        } else {
            $pregunta->update($atributos);
        }

        $this->auditoria->registrar('pregunta_filtro_candidato_guardada', $pregunta, $actor, ['clave' => $pregunta->clave]);

        return $pregunta;
    }

    private function claveUnica(string $pregunta): string
    {
        $base = mb_substr(str($pregunta)->slug('_')->toString(), 0, 50) ?: 'pregunta';
        $clave = $base;
        $n = 2;

        while (PreguntaFiltroCandidato::query()->where('clave', $clave)->exists()) {
            $clave = sprintf('%s_%d', $base, $n++);
        }

        return $clave;
    }
}
