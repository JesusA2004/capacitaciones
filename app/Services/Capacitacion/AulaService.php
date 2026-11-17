<?php

namespace App\Services\Capacitacion;

use App\Enums\EstadoEntregaActividad;
use App\Enums\EstadoIntentoCuestionario;
use App\Enums\EstadoProgreso;
use App\Enums\TipoLeccion;
use App\Models\Certificado;
use App\Models\Curso;
use App\Models\EntregaActividad;
use App\Models\InscripcionCurso;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\ProgresoLeccion;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Support\Collection;

/**
 * Vista de «aula» tipo Classroom de un curso (modo operativo), armada SOLO
 * con las entidades que ya existen (curso, módulos/lecciones, inscripciones,
 * intentos de cuestionario, entregas de actividad, sesiones en vivo y
 * constancias):
 *
 *   • Tablón: actividad reciente y próximas sesiones (derivado; todavía no
 *     hay publicaciones/avisos propios del aula — pendiente de negocio).
 *   • Trabajo asignado: lecciones calificables con su avance y lo que falta
 *     por calificar.
 *   • Calificaciones: participante × trabajo calificable.
 *   • Participantes: inscritos con su progreso y constancia.
 *
 * Participantes acotados con la misma regla que «Calificaciones» de
 * capacitación: AlcanceOrganizacionalService::idsColaboradoresParaRevision()
 * (alcance de sucursal → solo su gente; instructor/admin → todos).
 */
class AulaService
{
    private const TIPOS_CALIFICABLES = [TipoLeccion::Cuestionario, TipoLeccion::Actividad, TipoLeccion::SesionEnVivo];

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @return array<string, mixed>
     */
    public function aula(Curso $curso, User $actor): array
    {
        $curso->load([
            'responsable:id,name,apellidos',
            'modulos.lecciones.cuestionario:id,leccion_id,titulo,calificacion_minima',
            'modulos.lecciones.actividad:id,leccion_id,titulo,calificacion_minima,fecha_limite',
            'modulos.lecciones.sesionEnVivo:id,leccion_id,titulo,fecha_inicio,duracion_minutos',
        ]);

        $lecciones = $curso->modulos->flatMap(fn ($modulo) => $modulo->lecciones)->values();
        $obligatorias = $lecciones->where('obligatoria', true);
        $calificables = $lecciones->filter(fn (Leccion $l): bool => in_array($l->tipo, self::TIPOS_CALIFICABLES, true))->values();

        $idsAlcance = $this->alcance->idsColaboradoresParaRevision($actor);

        $inscripciones = InscripcionCurso::query()
            ->where('curso_id', $curso->id)
            ->when($idsAlcance !== null, fn ($q) => $q->whereIn('user_id', $idsAlcance ?? []))
            ->with('usuario:id,name,apellidos,email')
            ->orderBy('created_at')
            ->get();

        $idsUsuarios = $inscripciones->pluck('user_id');

        $progresos = ProgresoLeccion::query()
            ->whereIn('user_id', $idsUsuarios)
            ->whereIn('leccion_id', $lecciones->pluck('id'))
            ->where('estado', EstadoProgreso::Completada->value)
            ->get(['user_id', 'leccion_id', 'completado_en']);
        $completadasPorUsuario = $progresos->groupBy('user_id')->map(fn (Collection $filas) => $filas->pluck('leccion_id')->all());

        $cuestionarioIds = $calificables->map(fn (Leccion $l) => $l->cuestionario?->id)->filter()->values();
        $actividadIds = $calificables->map(fn (Leccion $l) => $l->actividad?->id)->filter()->values();

        $intentos = IntentoCuestionario::query()
            ->whereIn('cuestionario_id', $cuestionarioIds)
            ->whereIn('user_id', $idsUsuarios)
            ->get(['id', 'cuestionario_id', 'user_id', 'estado', 'calificacion', 'aprobado', 'enviado_en']);

        $entregas = EntregaActividad::query()
            ->whereIn('actividad_id', $actividadIds)
            ->whereIn('user_id', $idsUsuarios)
            ->orderByDesc('version')
            ->get(['id', 'actividad_id', 'user_id', 'estado', 'calificacion', 'entregado_en', 'version']);

        $certificados = Certificado::query()
            ->where('curso_id', $curso->id)
            ->whereIn('user_id', $idsUsuarios)
            ->get(['id', 'user_id', 'folio', 'emitido_en'])
            ->keyBy('user_id');

        $participantes = $inscripciones->map(function (InscripcionCurso $inscripcion) use ($obligatorias, $completadasPorUsuario, $certificados): array {
            $hechas = $completadasPorUsuario->get($inscripcion->user_id, []);
            $completadasObligatorias = $obligatorias->filter(fn (Leccion $l): bool => in_array($l->id, $hechas, true))->count();
            $porcentaje = $inscripcion->estado === EstadoProgreso::Completada
                ? 100
                : ($obligatorias->isEmpty() ? 0 : (int) round(100 * $completadasObligatorias / $obligatorias->count()));
            $certificado = $certificados->get($inscripcion->user_id);

            return [
                'user_id' => $inscripcion->user_id,
                'nombre' => $this->nombre($inscripcion->usuario),
                'correo' => $inscripcion->usuario?->email,
                'estado' => $inscripcion->estado->value,
                'estado_etiqueta' => $inscripcion->estado->etiqueta(),
                'progreso' => $porcentaje,
                'completado_en' => $inscripcion->completado_en?->toIso8601String(),
                'folio' => $certificado instanceof Certificado ? $certificado->folio : null,
            ];
        })->values();

        $trabajo = $calificables->map(function (Leccion $leccion) use ($intentos, $entregas, $completadasPorUsuario, $inscripciones): array {
            $completaron = $completadasPorUsuario->filter(fn (array $ids): bool => in_array($leccion->id, $ids, true))->count();
            $porCalificar = 0;
            $fecha = null;

            if ($leccion->cuestionario !== null) {
                $porCalificar = $intentos->where('cuestionario_id', $leccion->cuestionario->id)
                    ->where('estado', EstadoIntentoCuestionario::Enviado)->count();
            }

            if ($leccion->actividad !== null) {
                $porCalificar = $entregas->where('actividad_id', $leccion->actividad->id)
                    ->where('estado', EstadoEntregaActividad::Entregada)->count();
                $fecha = $leccion->actividad->fecha_limite?->toIso8601String();
            }

            if ($leccion->sesionEnVivo !== null) {
                $fecha = $leccion->sesionEnVivo->fecha_inicio->toIso8601String();
            }

            return [
                'leccion_id' => $leccion->id,
                'titulo' => $leccion->titulo,
                'tipo' => $leccion->tipo->value,
                'tipo_etiqueta' => $leccion->tipo->etiqueta(),
                'obligatoria' => (bool) $leccion->obligatoria,
                'completaron' => $completaron,
                'participantes' => $inscripciones->count(),
                'por_calificar' => $porCalificar,
                'fecha' => $fecha,
            ];
        })->values();

        $calificaciones = $inscripciones->map(function (InscripcionCurso $inscripcion) use ($calificables, $intentos, $entregas, $completadasPorUsuario): array {
            $hechas = $completadasPorUsuario->get($inscripcion->user_id, []);
            $celdas = [];

            foreach ($calificables as $leccion) {
                $celdas[(string) $leccion->id] = $this->celda($leccion, $inscripcion->user_id, $hechas, $intentos, $entregas);
            }

            return [
                'user_id' => $inscripcion->user_id,
                'nombre' => $this->nombre($inscripcion->usuario),
                'celdas' => $celdas,
            ];
        })->values();

        return [
            'curso' => [
                'id' => $curso->id,
                'titulo' => $curso->titulo,
                'descripcion' => $curso->descripcion,
                'objetivo' => $curso->objetivo,
                'estado' => $curso->estado->value,
                'estado_etiqueta' => $curso->estado->etiqueta(),
                'duracion_estimada_minutos' => $curso->duracion_estimada_minutos,
                'genera_constancia' => (bool) $curso->genera_constancia,
                'etiquetas' => $curso->etiquetas ?? [],
                'responsable' => $curso->responsable === null ? null : $this->nombre($curso->responsable),
                'modulos' => $curso->modulos->count(),
                'lecciones' => $lecciones->count(),
            ],
            'resumen' => [
                'participantes' => $inscripciones->count(),
                'completaron' => $inscripciones->where('estado', EstadoProgreso::Completada)->count(),
                'por_calificar' => (int) $trabajo->sum('por_calificar'),
                'constancias' => $certificados->count(),
                'progreso_promedio' => $participantes->isEmpty() ? 0 : (int) round((float) $participantes->avg('progreso')),
            ],
            'tablon' => $this->tablon($lecciones, $inscripciones, $intentos, $entregas, $certificados),
            'trabajo' => $trabajo->all(),
            'columnas' => $calificables->map(fn (Leccion $l): array => [
                'leccion_id' => $l->id,
                'titulo' => $l->titulo,
                'tipo' => $l->tipo->value,
            ])->values()->all(),
            'calificaciones' => $calificaciones->all(),
            'participantes' => $participantes->all(),
        ];
    }

    /**
     * @param  array<int, int>  $hechas
     * @param  Collection<int, IntentoCuestionario>  $intentos
     * @param  Collection<int, EntregaActividad>  $entregas
     * @return array{estado: string, valor: int|null}
     */
    private function celda(Leccion $leccion, int $userId, array $hechas, Collection $intentos, Collection $entregas): array
    {
        $completada = in_array($leccion->id, $hechas, true);

        if ($leccion->cuestionario !== null) {
            $propios = $intentos->where('cuestionario_id', $leccion->cuestionario->id)->where('user_id', $userId);
            $calificados = $propios->where('estado', EstadoIntentoCuestionario::Calificado);

            if ($calificados->isNotEmpty()) {
                $mejor = $calificados->max('calificacion');

                return ['estado' => $completada ? 'aprobado' : 'reprobado', 'valor' => $mejor === null ? null : (int) $mejor];
            }

            if ($propios->where('estado', EstadoIntentoCuestionario::Enviado)->isNotEmpty()) {
                return ['estado' => 'por_calificar', 'valor' => null];
            }
        }

        if ($leccion->actividad !== null) {
            $ultima = $entregas->where('actividad_id', $leccion->actividad->id)->where('user_id', $userId)->first();

            if ($ultima instanceof EntregaActividad) {
                return match ($ultima->estado) {
                    EstadoEntregaActividad::Entregada => ['estado' => 'por_calificar', 'valor' => null],
                    EstadoEntregaActividad::Aprobada => ['estado' => 'aprobado', 'valor' => $ultima->calificacion === null ? null : (int) $ultima->calificacion],
                    EstadoEntregaActividad::Rechazada => ['estado' => 'reprobado', 'valor' => $ultima->calificacion === null ? null : (int) $ultima->calificacion],
                };
            }
        }

        return ['estado' => $completada ? 'completado' : 'sin_entregar', 'valor' => null];
    }

    /**
     * Tablón derivado: próximas sesiones + lo último que pasó en el aula.
     *
     * @param  Collection<int, Leccion>  $lecciones
     * @param  Collection<int, InscripcionCurso>  $inscripciones
     * @param  Collection<int, IntentoCuestionario>  $intentos
     * @param  Collection<int, EntregaActividad>  $entregas
     * @param  Collection<int, Certificado>  $certificados
     * @return list<array{tipo: string, titulo: string, detalle: string, fecha: string}>
     */
    private function tablon(Collection $lecciones, Collection $inscripciones, Collection $intentos, Collection $entregas, Collection $certificados): array
    {
        $nombres = $inscripciones->mapWithKeys(fn (InscripcionCurso $i) => [$i->user_id => $this->nombre($i->usuario)]);
        $titulosCuestionario = $lecciones->filter(fn (Leccion $l): bool => $l->cuestionario !== null)
            ->mapWithKeys(fn (Leccion $l) => [$l->cuestionario?->id => $l->titulo]);
        $titulosActividad = $lecciones->filter(fn (Leccion $l): bool => $l->actividad !== null)
            ->mapWithKeys(fn (Leccion $l) => [$l->actividad?->id => $l->titulo]);

        $eventos = [];

        foreach ($lecciones as $leccion) {
            if ($leccion->sesionEnVivo !== null && $leccion->sesionEnVivo->fecha_inicio->isFuture()) {
                $eventos[] = ([
                    'tipo' => 'sesion',
                    'titulo' => sprintf('Sesión en vivo: %s', $leccion->sesionEnVivo->titulo),
                    'detalle' => sprintf('%s · %d min', $leccion->titulo, $leccion->sesionEnVivo->duracion_minutos),
                    'fecha' => $leccion->sesionEnVivo->fecha_inicio->toIso8601String(),
                ]);
            }
        }

        foreach ($entregas as $entrega) {
            $eventos[] = ([
                'tipo' => 'entrega',
                'titulo' => sprintf('%s entregó «%s»', (string) $nombres->get($entrega->user_id, 'Alguien'), (string) $titulosActividad->get($entrega->actividad_id, 'una actividad')),
                'detalle' => $entrega->estado->etiqueta(),
                'fecha' => $entrega->entregado_en->toIso8601String(),
            ]);
        }

        foreach ($intentos->whereNotNull('enviado_en') as $intento) {
            $eventos[] = ([
                'tipo' => 'cuestionario',
                'titulo' => sprintf('%s envió «%s»', (string) $nombres->get($intento->user_id, 'Alguien'), (string) $titulosCuestionario->get($intento->cuestionario_id, 'un cuestionario')),
                'detalle' => $intento->estado->etiqueta(),
                'fecha' => $intento->enviado_en?->toIso8601String() ?? '',
            ]);
        }

        foreach ($certificados as $certificado) {
            $eventos[] = ([
                'tipo' => 'constancia',
                'titulo' => sprintf('%s obtuvo su constancia', (string) $nombres->get($certificado->user_id, 'Alguien')),
                'detalle' => sprintf('Folio %s', $certificado->folio),
                'fecha' => $certificado->emitido_en->toIso8601String(),
            ]);
        }

        usort($eventos, fn (array $a, array $b): int => strcmp($b['fecha'], $a['fecha']));

        return array_slice($eventos, 0, 30);
    }

    private function nombre(?User $usuario): string
    {
        if ($usuario === null) {
            return 'Usuario eliminado';
        }

        return trim(sprintf('%s %s', $usuario->name, (string) $usuario->apellidos));
    }
}
