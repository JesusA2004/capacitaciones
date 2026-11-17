<?php

namespace App\Services\Capacitacion;

use App\Enums\EstadoEntregaActividad;
use App\Enums\EstadoProgreso;
use App\Enums\TipoLeccion;
use App\Models\AsignacionUsuario;
use App\Models\Certificado;
use App\Models\Curso;
use App\Models\EntregaActividad;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\User;
use App\Services\Certificados\CertificacionMrLanaService;
use Illuminate\Support\Collection;

/**
 * «Mi capacitación» del colaborador (Mi espacio): sus aulas (cursos
 * inscritos con progreso), sus pendientes (lo siguiente que puede hacer en
 * cada aula) y sus constancias + nivel de la Certificación Mr. Lana.
 *
 * Siempre opera sobre el usuario autenticado — nunca recibe el id de otra
 * persona. El progreso y el bloqueo de cada lección los decide
 * ProgresoService (misma regla que el detalle del curso).
 */
class MiCapacitacionService
{
    public function __construct(
        private readonly ProgresoService $progreso,
        private readonly CertificacionMrLanaService $certificacion,
    ) {}

    /**
     * @return array{
     *     aulas: list<array<string, mixed>>,
     *     pendientes: list<array<string, mixed>>,
     *     certificados: list<array{id: int, folio: string, curso: string, curso_id: int, emitido_en: string|null}>,
     *     certificacion: array<string, mixed>,
     *     resumen: array{en_curso: int, completadas: int, pendientes: int, constancias: int, minutos_completados: int, progreso_promedio: int}
     * }
     */
    public function panel(User $usuario): array
    {
        $inscripciones = $usuario->inscripcionesCurso()
            ->with([
                'curso' => fn ($q) => $q->withTrashed(),
                'curso.modulos.lecciones.requisitos:id,titulo',
                'curso.modulos.lecciones.cuestionario:id,leccion_id',
                'curso.modulos.lecciones.actividad:id,leccion_id,fecha_limite',
                'curso.modulos.lecciones.sesionEnVivo:id,leccion_id,fecha_inicio',
                'asignacionUsuario:id,fecha_limite',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (InscripcionCurso $inscripcion): bool => $inscripcion->curso !== null)
            ->values();

        $certificados = Certificado::query()
            ->where('user_id', $usuario->id)
            ->with('curso:id,titulo')
            ->orderByDesc('emitido_en')
            ->get();
        $certificadoPorCurso = $certificados->keyBy('curso_id');

        $actividadIds = $inscripciones
            ->flatMap(fn (InscripcionCurso $i) => $this->lecciones($i->curso))
            ->map(fn (Leccion $l) => $l->actividad?->id)
            ->filter()
            ->values();

        $entregasEnRevision = EntregaActividad::query()
            ->where('user_id', $usuario->id)
            ->whereIn('actividad_id', $actividadIds)
            ->where('estado', EstadoEntregaActividad::Entregada->value)
            ->pluck('actividad_id')
            ->all();

        $aulas = [];
        $pendientes = [];
        $minutosCompletados = 0;

        foreach ($inscripciones as $inscripcion) {
            $curso = $inscripcion->curso;
            $lecciones = $this->lecciones($curso);
            $obligatorias = $lecciones->where('obligatoria', true);
            $completadas = $obligatorias->filter(fn (Leccion $l): bool => $this->progreso->leccionCompletada($usuario, $l))->count();
            $porcentaje = $inscripcion->estado === EstadoProgreso::Completada
                ? 100
                : ($obligatorias->isEmpty() ? 0 : (int) round(100 * $completadas / $obligatorias->count()));

            if ($inscripcion->estado === EstadoProgreso::Completada) {
                $minutosCompletados += (int) ($curso->duracion_estimada_minutos ?? 0);
            }

            $fechaLimite = $inscripcion->asignacionUsuario instanceof AsignacionUsuario
                ? $inscripcion->asignacionUsuario->fecha_limite
                : null;
            $certificado = $certificadoPorCurso->get($curso->id);

            $aulas[] = [
                'inscripcion_id' => $inscripcion->id,
                'curso' => [
                    'id' => $curso->id,
                    'titulo' => $curso->titulo,
                    'descripcion' => $curso->descripcion,
                    'duracion_estimada_minutos' => $curso->duracion_estimada_minutos,
                    'genera_constancia' => (bool) $curso->genera_constancia,
                    'etiquetas' => $curso->etiquetas ?? [],
                ],
                'estado' => $inscripcion->estado->value,
                'estado_etiqueta' => $inscripcion->estado->etiqueta(),
                'progreso' => $porcentaje,
                'lecciones_total' => $lecciones->count(),
                'lecciones_completadas' => $lecciones->filter(fn (Leccion $l): bool => $this->progreso->leccionCompletada($usuario, $l))->count(),
                'fecha_limite' => $fechaLimite?->toIso8601String(),
                'completado_en' => $inscripcion->completado_en?->toIso8601String(),
                'certificado_id' => $certificado instanceof Certificado ? $certificado->id : null,
            ];

            if ($inscripcion->estado === EstadoProgreso::Completada) {
                continue;
            }

            foreach ($lecciones as $leccion) {
                if ($this->progreso->leccionCompletada($usuario, $leccion)) {
                    continue;
                }

                if ($this->progreso->estadoBloqueoLeccion($usuario, $leccion)['bloqueada']) {
                    continue;
                }

                $enRevision = $leccion->actividad !== null && in_array($leccion->actividad->id, $entregasEnRevision, true);

                $pendientes[] = [
                    'leccion_id' => $leccion->id,
                    'titulo' => $leccion->titulo,
                    'tipo' => $leccion->tipo->value,
                    'tipo_etiqueta' => $leccion->tipo->etiqueta(),
                    'obligatoria' => (bool) $leccion->obligatoria,
                    'curso_id' => $curso->id,
                    'curso' => $curso->titulo,
                    'estado' => $enRevision ? 'en_revision' : 'por_hacer',
                    'fecha' => $this->fechaDeLeccion($leccion, $fechaLimite?->toIso8601String()),
                ];
            }
        }

        usort($pendientes, function (array $a, array $b): int {
            if ($a['fecha'] === $b['fecha']) {
                return 0;
            }

            if ($a['fecha'] === null) {
                return 1;
            }

            if ($b['fecha'] === null) {
                return -1;
            }

            return strcmp((string) $a['fecha'], (string) $b['fecha']);
        });

        $enCurso = collect($aulas)->where('estado', '!=', EstadoProgreso::Completada->value)->count();

        return [
            'aulas' => $aulas,
            'pendientes' => $pendientes,
            'certificados' => array_values($certificados->map(fn (Certificado $c): array => [
                'id' => $c->id,
                'folio' => $c->folio,
                'curso' => $c->curso === null ? 'Curso archivado' : $c->curso->titulo,
                'curso_id' => $c->curso_id,
                'emitido_en' => $c->emitido_en->toIso8601String(),
            ])->all()),
            'certificacion' => $this->certificacion->resumenPorConteo($certificados->count()),
            'resumen' => [
                'en_curso' => $enCurso,
                'completadas' => count($aulas) - $enCurso,
                'pendientes' => count(array_filter($pendientes, fn (array $p): bool => $p['estado'] === 'por_hacer')),
                'constancias' => $certificados->count(),
                'minutos_completados' => $minutosCompletados,
                'progreso_promedio' => count($aulas) === 0 ? 0 : (int) round(collect($aulas)->avg('progreso') ?? 0),
            ],
        ];
    }

    /**
     * Resumen para la tarjeta «Mi capacitación» del inicio del colaborador
     * (Portal). Mismos números que el panel; null si no tiene cursos.
     *
     * @return array{en_curso: int, pendientes: int, progreso_promedio: int, constancias: int, siguiente: array<string, mixed>|null}|null
     */
    public function resumenPortal(User $usuario): ?array
    {
        if (! $usuario->inscripcionesCurso()->exists()) {
            return null;
        }

        $panel = $this->panel($usuario);
        $siguiente = collect($panel['pendientes'])->firstWhere('estado', 'por_hacer');

        return [
            'en_curso' => $panel['resumen']['en_curso'],
            'pendientes' => $panel['resumen']['pendientes'],
            'progreso_promedio' => $panel['resumen']['progreso_promedio'],
            'constancias' => $panel['resumen']['constancias'],
            'siguiente' => $siguiente === null ? null : [
                'leccion_id' => $siguiente['leccion_id'],
                'titulo' => $siguiente['titulo'],
                'tipo_etiqueta' => $siguiente['tipo_etiqueta'],
                'curso_id' => $siguiente['curso_id'],
                'curso' => $siguiente['curso'],
                'fecha' => $siguiente['fecha'],
            ],
        ];
    }

    /**
     * Lecciones del curso en orden, con la relación inversa hidratada a mano
     * (ProgresoService sube lección → módulo → curso; sin esto, N+1).
     *
     * @return Collection<int, Leccion>
     */
    private function lecciones(Curso $curso): Collection
    {
        foreach ($curso->modulos as $modulo) {
            $modulo->setRelation('curso', $curso);

            foreach ($modulo->lecciones as $leccion) {
                $leccion->setRelation('modulo', $modulo);
            }
        }

        return $curso->modulos->flatMap(fn ($modulo) => $modulo->lecciones)->values();
    }

    private function fechaDeLeccion(Leccion $leccion, ?string $fechaAsignacion): ?string
    {
        if ($leccion->tipo === TipoLeccion::SesionEnVivo && $leccion->sesionEnVivo !== null) {
            return $leccion->sesionEnVivo->fecha_inicio->toIso8601String();
        }

        if ($leccion->tipo === TipoLeccion::Actividad && $leccion->actividad?->fecha_limite !== null) {
            return $leccion->actividad->fecha_limite->toIso8601String();
        }

        return $fechaAsignacion;
    }
}
