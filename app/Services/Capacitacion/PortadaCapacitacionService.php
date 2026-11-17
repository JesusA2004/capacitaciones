<?php

namespace App\Services\Capacitacion;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoCurso;
use App\Enums\EstadoEntregaActividad;
use App\Enums\EstadoIntentoCuestionario;
use App\Enums\EstadoProgreso;
use App\Models\AsignacionUsuario;
use App\Models\Certificado;
use App\Models\Curso;
use App\Models\EntregaActividad;
use App\Models\InscripcionCurso;
use App\Models\IntentoCuestionario;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Portada de Capacitación en modo operativo: indicadores, tarjetas de aulas,
 * serie mensual de cursos terminados y próximas sesiones.
 *
 * Todo lo que cuenta personas (inscripciones, por calificar, constancias)
 * se acota con la regla de capacitación de AlcanceOrganizacionalService
 * (idsColaboradoresParaRevision): alcance de sucursal → solo su gente.
 */
class PortadaCapacitacionService
{
    private const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @return array<string, mixed>
     */
    public function portada(User $actor): array
    {
        $ids = $this->alcance->idsColaboradoresParaRevision($actor);
        $acotar = function (Builder $query, string $columna = 'user_id') use ($ids): Builder {
            return $ids === null ? $query : $query->whereIn($columna, $ids);
        };

        $dias = (int) config('capacitacion.dias_proximos', 14);
        $ahora = now();

        $estados = [];
        foreach (EstadoProgreso::cases() as $estado) {
            $estados[$estado->value] = $acotar(InscripcionCurso::query())->where('estado', $estado->value)->count();
        }
        $totalInscripciones = array_sum($estados);

        $porCalificar = $acotar(IntentoCuestionario::query())->where('estado', EstadoIntentoCuestionario::Enviado->value)->count()
            + $acotar(EntregaActividad::query())->where('estado', EstadoEntregaActividad::Entregada->value)->count();

        $vencen = $acotar(AsignacionUsuario::query())
            ->whereNotIn('estado', [EstadoAsignacion::Completada->value, EstadoAsignacion::Cancelada->value])
            ->whereBetween('fecha_limite', [$ahora, $ahora->copy()->addDays($dias)])
            ->count();

        $cursos = Curso::query()
            ->where('estado', '!=', EstadoCurso::Archivado->value)
            ->withCount([
                'modulos',
                'inscripciones as inscritos' => fn (Builder $q) => $acotar($q),
                'inscripciones as completaron' => fn (Builder $q) => $acotar($q)->where('estado', EstadoProgreso::Completada->value),
            ])
            ->with('responsable:id,name,apellidos')
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [EstadoCurso::Publicado->value])
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get();

        return [
            'indicadores' => [
                'cursos_publicados' => Curso::query()->where('estado', EstadoCurso::Publicado->value)->count(),
                'cursos_borrador' => Curso::query()->where('estado', EstadoCurso::Borrador->value)->count(),
                'participantes' => $acotar(InscripcionCurso::query())->distinct()->count('user_id'),
                'inscripciones' => $totalInscripciones,
                'completadas' => $estados[EstadoProgreso::Completada->value],
                'tasa_finalizacion' => $totalInscripciones === 0 ? 0 : (int) round(100 * $estados[EstadoProgreso::Completada->value] / $totalInscripciones),
                'por_calificar' => $porCalificar,
                'constancias' => $acotar(Certificado::query())->count(),
                'vencen_pronto' => $vencen,
            ],
            'estados' => array_map(fn (EstadoProgreso $estado): array => [
                'clave' => $estado->value,
                'etiqueta' => $estado->etiqueta(),
                'total' => $estados[$estado->value],
            ], EstadoProgreso::cases()),
            'serie' => $this->serieMensual($acotar(InscripcionCurso::query())),
            'cursos' => $cursos->map(fn (Curso $curso): array => [
                'id' => $curso->id,
                'titulo' => $curso->titulo,
                'descripcion' => $curso->descripcion,
                'estado' => $curso->estado->value,
                'estado_etiqueta' => $curso->estado->etiqueta(),
                'etiquetas' => $curso->etiquetas ?? [],
                'duracion_estimada_minutos' => $curso->duracion_estimada_minutos,
                'genera_constancia' => (bool) $curso->genera_constancia,
                'modulos' => (int) $curso->getAttribute('modulos_count'),
                'inscritos' => (int) $curso->getAttribute('inscritos'),
                'completaron' => (int) $curso->getAttribute('completaron'),
                'responsable' => $curso->responsable === null ? null : trim(sprintf('%s %s', $curso->responsable->name, (string) $curso->responsable->apellidos)),
            ])->values()->all(),
            'proximas_sesiones' => SesionEnVivo::query()
                ->whereBetween('fecha_inicio', [$ahora, $ahora->copy()->addDays($dias)])
                ->with('leccion.modulo.curso:id,titulo')
                ->orderBy('fecha_inicio')
                ->limit(6)
                ->get()
                ->map(fn (SesionEnVivo $sesion): array => [
                    'id' => $sesion->id,
                    'titulo' => $sesion->titulo,
                    'fecha_inicio' => $sesion->fecha_inicio->toIso8601String(),
                    'duracion_minutos' => $sesion->duracion_minutos,
                    'curso' => $sesion->leccion?->modulo?->curso?->titulo,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Cursos terminados por mes (últimos 6 meses, incluido el actual).
     * Se agrupa en PHP para no depender de funciones de fecha del motor.
     *
     * @param  Builder<InscripcionCurso>  $inscripciones
     * @return list<array{mes: string, etiqueta: string, completadas: int}>
     */
    private function serieMensual(Builder $inscripciones): array
    {
        $inicio = now()->startOfMonth()->subMonths(5);

        $fechas = $inscripciones
            ->where('estado', EstadoProgreso::Completada->value)
            ->where('completado_en', '>=', $inicio)
            ->pluck('completado_en');

        $serie = [];
        for ($i = 0; $i < 6; $i++) {
            $mes = $inicio->copy()->addMonths($i);
            $serie[$mes->format('Y-m')] = [
                'mes' => $mes->format('Y-m'),
                'etiqueta' => self::MESES[$mes->month - 1],
                'completadas' => 0,
            ];
        }

        foreach ($fechas as $fecha) {
            if (! $fecha instanceof CarbonInterface) {
                continue;
            }

            $clave = $fecha->format('Y-m');

            if (isset($serie[$clave])) {
                $serie[$clave]['completadas']++;
            }
        }

        return array_values($serie);
    }
}
