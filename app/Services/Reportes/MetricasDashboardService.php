<?php

namespace App\Services\Reportes;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoEntregaActividad;
use App\Enums\EstadoIntentoCuestionario;
use App\Enums\EstadoProgreso;
use App\Enums\EstadoSesionEnVivo;
use App\Enums\EstadoUsuario;
use App\Enums\TipoLeccion;
use App\Models\EntregaActividad;
use App\Models\InscripcionCurso;
use App\Models\IntentoCuestionario;
use App\Models\ProgresoLeccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Metricas resumidas para los tres dashboards por rol. Deliberadamente no
 * reutiliza ReporteCumplimientoService::resumenGeneral() como unico dato:
 * un dashboard necesita ademas "cosas por hacer ahora mismo" (calificar,
 * asistir a una sesion), no solo el cumplimiento historico.
 *
 * Las graficas agregadas (fase de modernizacion visual, ver
 * docs/AUDITORIA_CUMPLIMIENTO.md) viven en graficasOrganizacion() /
 * graficasColaborador(): la primera es el conjunto completo para
 * administradores/gerentes de sucursal (siempre acotado por
 * AlcanceOrganizacionalService), la segunda es el subconjunto personal que
 * ve un colaborador sobre si mismo, para no saturarlo con metricas
 * organizacionales que no puede accionar.
 */
class MetricasDashboardService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ReporteCumplimientoService $reporteCumplimiento,
    ) {}

    /**
     * @return array{cursosEnProgreso: int, cursosCompletados: int, proximasSesiones: Collection<int, SesionEnVivo>, graficas: array<string, mixed>}
     */
    public function paraColaborador(User $usuario): array
    {
        $inscripciones = $usuario->inscripcionesCurso();

        return [
            'cursosEnProgreso' => (clone $inscripciones)->where('estado', EstadoProgreso::EnProgreso->value)->count()
                + (clone $inscripciones)->where('estado', EstadoProgreso::Pendiente->value)->count(),
            'cursosCompletados' => (clone $inscripciones)->where('estado', EstadoProgreso::Completada->value)->count(),
            'proximasSesiones' => $this->proximasSesionesDeUsuario($usuario),
            'graficas' => $this->graficasColaborador($usuario),
        ];
    }

    /**
     * @return array{resumen: array{total_asignaciones: int, completadas: int, vencidas: int, porcentaje_cumplimiento: float}, cumplimientoPorSucursal: Collection<int, array{sucursal_id: int, sucursal: string, total: int, completadas: int, porcentaje: float}>, pendientesCalificar: int, proximasSesiones: Collection<int, SesionEnVivo>, graficas: array<string, mixed>}
     */
    public function paraSucursal(User $usuario): array
    {
        return [
            'resumen' => $this->reporteCumplimiento->resumenGeneral($usuario),
            'cumplimientoPorSucursal' => $this->reporteCumplimiento->cumplimientoPorSucursal($usuario),
            'pendientesCalificar' => $this->pendientesDeCalificar($usuario),
            'proximasSesiones' => $this->proximasSesionesDeSucursal($usuario),
            'graficas' => $this->graficasOrganizacion($usuario),
        ];
    }

    /**
     * @return array{resumen: array{total_asignaciones: int, completadas: int, vencidas: int, porcentaje_cumplimiento: float}, cumplimientoPorSucursal: Collection<int, array{sucursal_id: int, sucursal: string, total: int, completadas: int, porcentaje: float}>, graficas: array<string, mixed>}
     */
    public function global(User $usuario): array
    {
        return [
            'resumen' => $this->reporteCumplimiento->resumenGeneral($usuario),
            'cumplimientoPorSucursal' => $this->reporteCumplimiento->cumplimientoPorSucursal($usuario),
            'graficas' => $this->graficasOrganizacion($usuario),
        ];
    }

    /**
     * Conjunto completo de graficas para los dashboards Global/Sucursal,
     * siempre acotado a los colaboradores que el usuario puede ver.
     *
     * @return array<string, mixed>
     */
    private function graficasOrganizacion(User $usuario): array
    {
        $usuariosVisiblesIds = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)->pluck('id');
        $creadoresVisiblesIds = $usuariosVisiblesIds;

        return [
            'cursosPorEstado' => $this->cursosPorEstado($usuariosVisiblesIds),
            'cumplimientoPorDepartamento' => $this->reporteCumplimiento->cumplimientoPorDepartamento($usuario),
            'colaboradoresActivos' => $this->colaboradoresActivos($usuariosVisiblesIds),
            'calificacionPromedio' => $this->calificacionPromedio($usuariosVisiblesIds),
            'asistenciaSesiones' => $this->asistenciaSesionesPorCreadores($creadoresVisiblesIds),
            'videosCompletados' => $this->videosCompletados($usuariosVisiblesIds),
            'cuestionarios' => $this->cuestionariosAprobadosVsReprobados($usuariosVisiblesIds),
            'actividadesPendientes' => $this->actividadesPendientesPorAntiguedad($usuario),
            'evolucionMensual' => $this->evolucionMensual($usuariosVisiblesIds),
            'topCursosAvance' => $this->topCursosPorAvance($usuariosVisiblesIds),
            'cursosMayorAbandono' => $this->cursosConMayorAbandono($usuariosVisiblesIds),
            'usuariosPendientesCriticos' => $this->usuariosConPendientesCriticos($usuariosVisiblesIds),
        ];
    }

    /**
     * Subconjunto personal para el dashboard de Colaborador: solo datos
     * sobre si mismo, sin desgloses organizacionales.
     *
     * @return array<string, mixed>
     */
    private function graficasColaborador(User $usuario): array
    {
        $idPropio = collect([$usuario->id]);

        return [
            'cursosPorEstado' => $this->cursosPorEstado($idPropio),
            'calificacionPromedio' => $this->calificacionPromedio($idPropio),
            'asistenciaSesiones' => $this->asistenciaSesionesDeUsuario($usuario),
            'videosCompletados' => $this->videosCompletados($idPropio),
        ];
    }

    /**
     * @return Collection<int, SesionEnVivo>
     */
    private function proximasSesionesDeUsuario(User $usuario): Collection
    {
        $cursosIds = $usuario->inscripcionesCurso()->pluck('curso_id');

        return SesionEnVivo::query()
            ->whereHas('leccion.modulo', fn ($q) => $q->whereIn('curso_id', $cursosIds))
            ->where('estado', EstadoSesionEnVivo::Programada->value)
            ->where('fecha_inicio', '>=', now())
            ->orderBy('fecha_inicio')
            ->with('leccion:id,titulo,curso_modulo_id')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, SesionEnVivo>
     */
    private function proximasSesionesDeSucursal(User $usuario): Collection
    {
        $usuariosVisiblesIds = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)->pluck('id');

        return SesionEnVivo::query()
            ->whereIn('creado_por', $usuariosVisiblesIds)
            ->where('estado', EstadoSesionEnVivo::Programada->value)
            ->where('fecha_inicio', '>=', now())
            ->orderBy('fecha_inicio')
            ->with('leccion:id,titulo,curso_modulo_id')
            ->limit(5)
            ->get();
    }

    private function pendientesDeCalificar(User $usuario): int
    {
        if (! $usuario->can('respuestas.calificar')) {
            return 0;
        }

        return IntentoCuestionario::query()->where('estado', EstadoIntentoCuestionario::Enviado->value)->count()
            + EntregaActividad::query()->where('estado', 'entregada')->count();
    }

    /**
     * @param  Collection<int, int>  $usuariosVisiblesIds
     * @return array{completados: int, en_progreso: int, pendientes: int}
     */
    private function cursosPorEstado(Collection $usuariosVisiblesIds): array
    {
        $conteos = InscripcionCurso::query()
            ->whereIn('user_id', $usuariosVisiblesIds)
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'completados' => (int) ($conteos[EstadoProgreso::Completada->value] ?? 0),
            'en_progreso' => (int) ($conteos[EstadoProgreso::EnProgreso->value] ?? 0),
            'pendientes' => (int) ($conteos[EstadoProgreso::Pendiente->value] ?? 0),
        ];
    }

    /**
     * @param  Collection<int, int>  $usuariosVisiblesIds
     */
    private function colaboradoresActivos(Collection $usuariosVisiblesIds): int
    {
        return User::query()
            ->whereIn('id', $usuariosVisiblesIds)
            ->where('estatus', EstadoUsuario::Activo->value)
            ->count();
    }

    /**
     * @param  Collection<int, int>  $usuariosVisiblesIds
     */
    private function calificacionPromedio(Collection $usuariosVisiblesIds): float
    {
        $promedio = InscripcionCurso::query()
            ->whereIn('user_id', $usuariosVisiblesIds)
            ->whereNotNull('calificacion_final')
            ->avg('calificacion_final');

        return $promedio !== null ? round((float) $promedio, 1) : 0.0;
    }

    /**
     * Distribucion de asistencia (presente/parcial/ausente/...) de las
     * sesiones creadas por los usuarios visibles, en los ultimos 30 dias.
     *
     * @param  Collection<int, int>  $creadoresVisiblesIds
     * @return array<string, int>
     */
    private function asistenciaSesionesPorCreadores(Collection $creadoresVisiblesIds): array
    {
        return DB::table('asistencias')
            ->join('sesiones_en_vivo', 'sesiones_en_vivo.id', '=', 'asistencias.sesion_en_vivo_id')
            ->whereIn('sesiones_en_vivo.creado_por', $creadoresVisiblesIds)
            ->where('sesiones_en_vivo.fecha_inicio', '>=', now()->subDays(30))
            ->selectRaw('asistencias.estado, count(*) as total')
            ->groupBy('asistencias.estado')
            ->pluck('total', 'estado')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function asistenciaSesionesDeUsuario(User $usuario): array
    {
        return DB::table('asistencias')
            ->where('user_id', $usuario->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Aproximacion: no existe un catalogo separado de "video visto por
     * usuario esperado"; se compara contra el universo total de lecciones
     * de tipo video multiplicado por los usuarios visibles, como cota
     * superior razonable para una grafica de avance, no como metrica de
     * cumplimiento exacta (esa vive en ReporteCumplimientoService).
     *
     * @param  Collection<int, int>  $usuariosVisiblesIds
     * @return array{completados: int, total: int}
     */
    private function videosCompletados(Collection $usuariosVisiblesIds): array
    {
        $totalVideos = DB::table('lecciones')->where('tipo', TipoLeccion::Video->value)->whereNull('deleted_at')->count();

        if ($totalVideos === 0) {
            return ['completados' => 0, 'total' => 0];
        }

        $completados = ProgresoLeccion::query()
            ->whereIn('progreso_lecciones.user_id', $usuariosVisiblesIds)
            ->where('progreso_lecciones.estado', EstadoProgreso::Completada->value)
            ->join('lecciones', 'lecciones.id', '=', 'progreso_lecciones.leccion_id')
            ->where('lecciones.tipo', TipoLeccion::Video->value)
            ->count();

        return [
            'completados' => $completados,
            'total' => $totalVideos * max(1, $usuariosVisiblesIds->count()),
        ];
    }

    /**
     * Cuenta el ultimo intento calificado por (cuestionario, usuario), no
     * cada intento: un colaborador con varios intentos del mismo
     * cuestionario solo debe contar una vez en la grafica.
     *
     * @param  Collection<int, int>  $usuariosVisiblesIds
     * @return array{aprobados: int, reprobados: int}
     */
    private function cuestionariosAprobadosVsReprobados(Collection $usuariosVisiblesIds): array
    {
        $ultimosIntentosIds = IntentoCuestionario::query()
            ->whereIn('user_id', $usuariosVisiblesIds)
            ->whereNotNull('aprobado')
            ->groupBy('cuestionario_id', 'user_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        $conteo = IntentoCuestionario::query()
            ->whereIn('id', $ultimosIntentosIds)
            ->selectRaw('aprobado, count(*) as total')
            ->groupBy('aprobado')
            ->pluck('total', 'aprobado');

        return [
            'aprobados' => (int) ($conteo[1] ?? 0),
            'reprobados' => (int) ($conteo[0] ?? 0),
        ];
    }

    /**
     * Reutiliza AlcanceOrganizacionalService::idsColaboradoresParaRevision(),
     * el mismo criterio con el que ya se filtran las entregas que un
     * revisor puede calificar, para no duplicar la regla de aislamiento.
     *
     * @return array{recientes: int, atrasadas: int, criticas: int}
     */
    private function actividadesPendientesPorAntiguedad(User $usuario): array
    {
        $idsPermitidos = $this->alcance->idsColaboradoresParaRevision($usuario);

        $query = EntregaActividad::query()->where('estado', EstadoEntregaActividad::Entregada->value);

        if ($idsPermitidos !== null) {
            $query->whereIn('user_id', $idsPermitidos);
        }

        $entregas = $query->get(['entregado_en']);

        return [
            'recientes' => $entregas->filter(fn ($e) => $e->entregado_en->diffInDays(now()) <= 2)->count(),
            'atrasadas' => $entregas->filter(fn ($e) => $e->entregado_en->diffInDays(now()) > 2 && $e->entregado_en->diffInDays(now()) <= 7)->count(),
            'criticas' => $entregas->filter(fn ($e) => $e->entregado_en->diffInDays(now()) > 7)->count(),
        ];
    }

    /**
     * Cursos completados por mes en los ultimos 6 meses. Se recorre mes a
     * mes con whereBetween (en vez de un GROUP BY con funciones de fecha
     * crudas) para que la consulta sea identica en SQLite (tests) y MySQL
     * (produccion).
     *
     * @param  Collection<int, int>  $usuariosVisiblesIds
     * @return Collection<int, array{mes: string, completados: int}>
     */
    private function evolucionMensual(Collection $usuariosVisiblesIds): Collection
    {
        return collect(range(5, 0))->map(function (int $mesesAtras) use ($usuariosVisiblesIds) {
            $inicioMes = now()->subMonths($mesesAtras)->startOfMonth();
            $finMes = $inicioMes->copy()->endOfMonth();

            $completados = InscripcionCurso::query()
                ->whereIn('user_id', $usuariosVisiblesIds)
                ->whereBetween('completado_en', [$inicioMes, $finMes])
                ->count();

            return ['mes' => $inicioMes->translatedFormat('M Y'), 'completados' => $completados];
        });
    }

    /**
     * % de avance promedio (lecciones completadas / lecciones totales del
     * curso) entre los colaboradores inscritos, para los cursos con
     * inscripciones visibles.
     *
     * @param  Collection<int, int>  $usuariosVisiblesIds
     * @return Collection<int, array{curso_id: int, curso: string, porcentaje: float}>
     */
    private function topCursosPorAvance(Collection $usuariosVisiblesIds, int $limite = 5): Collection
    {
        $cursosIds = InscripcionCurso::query()->whereIn('user_id', $usuariosVisiblesIds)->distinct()->pluck('curso_id');

        if ($cursosIds->isEmpty()) {
            return collect();
        }

        $totalLeccionesPorCurso = DB::table('lecciones')
            ->join('curso_modulos', 'curso_modulos.id', '=', 'lecciones.curso_modulo_id')
            ->whereIn('curso_modulos.curso_id', $cursosIds)
            ->whereNull('lecciones.deleted_at')
            ->groupBy('curso_modulos.curso_id')
            ->selectRaw('curso_modulos.curso_id, count(*) as total')
            ->pluck('total', 'curso_modulos.curso_id');

        $completadasPorCursoYUsuario = DB::table('progreso_lecciones')
            ->join('lecciones', 'lecciones.id', '=', 'progreso_lecciones.leccion_id')
            ->join('curso_modulos', 'curso_modulos.id', '=', 'lecciones.curso_modulo_id')
            ->whereIn('progreso_lecciones.user_id', $usuariosVisiblesIds)
            ->where('progreso_lecciones.estado', EstadoProgreso::Completada->value)
            ->whereIn('curso_modulos.curso_id', $cursosIds)
            ->groupBy('curso_modulos.curso_id', 'progreso_lecciones.user_id')
            ->selectRaw('curso_modulos.curso_id as curso_id, count(*) as completadas')
            ->get()
            ->groupBy('curso_id');

        $titulos = DB::table('cursos')->whereIn('id', $cursosIds)->pluck('titulo', 'id');

        return $cursosIds
            ->map(function (int $cursoId) use ($completadasPorCursoYUsuario, $totalLeccionesPorCurso, $titulos) {
                $total = (int) ($totalLeccionesPorCurso[$cursoId] ?? 0);
                $filas = $completadasPorCursoYUsuario->get($cursoId, collect());

                $porcentaje = $total > 0 && $filas->isNotEmpty()
                    ? round($filas->avg(fn ($fila) => min(100, ($fila->completadas / $total) * 100)), 1)
                    : 0.0;

                return [
                    'curso_id' => $cursoId,
                    'curso' => (string) ($titulos[$cursoId] ?? '—'),
                    'porcentaje' => $porcentaje,
                ];
            })
            ->sortByDesc('porcentaje')
            ->take($limite)
            ->values();
    }

    /**
     * "Abandono" se aproxima como inscripciones cuya asignacion venció sin
     * haberse completado: el modelo de datos no tiene un estado
     * "abandonado" explícito en `inscripciones_curso`.
     *
     * @param  Collection<int, int>  $usuariosVisiblesIds
     * @return Collection<int, array{curso_id: int, curso: string, porcentaje: float}>
     */
    private function cursosConMayorAbandono(Collection $usuariosVisiblesIds, int $limite = 5): Collection
    {
        return DB::table('inscripciones_curso')
            ->join('asignaciones_usuario', 'asignaciones_usuario.id', '=', 'inscripciones_curso.asignacion_usuario_id')
            ->join('cursos', 'cursos.id', '=', 'inscripciones_curso.curso_id')
            ->whereIn('inscripciones_curso.user_id', $usuariosVisiblesIds)
            ->groupBy('cursos.id', 'cursos.titulo')
            ->selectRaw('cursos.id as curso_id, cursos.titulo as curso, count(*) as total')
            ->selectRaw("sum(case when asignaciones_usuario.estado = 'vencida' and inscripciones_curso.estado != 'completada' then 1 else 0 end) as abandonos")
            ->get()
            ->map(fn ($fila) => [
                'curso_id' => (int) $fila->curso_id,
                'curso' => (string) $fila->curso,
                'porcentaje' => $fila->total > 0 ? round(($fila->abandonos / $fila->total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('porcentaje')
            ->take($limite)
            ->values();
    }

    /**
     * @param  Collection<int, int>  $usuariosVisiblesIds
     * @return Collection<int, array{id: int, nombre: string, vencidas: int}>
     */
    private function usuariosConPendientesCriticos(Collection $usuariosVisiblesIds, int $limite = 5): Collection
    {
        return DB::table('asignaciones_usuario')
            ->join('users', 'users.id', '=', 'asignaciones_usuario.user_id')
            ->whereIn('users.id', $usuariosVisiblesIds)
            ->where('asignaciones_usuario.estado', EstadoAsignacion::Vencida->value)
            ->groupBy('users.id', 'users.name', 'users.apellidos')
            ->selectRaw('users.id as id, users.name as name, users.apellidos as apellidos, count(*) as vencidas')
            ->orderByDesc('vencidas')
            ->limit($limite)
            ->get()
            ->map(fn ($fila) => [
                'id' => (int) $fila->id,
                'nombre' => trim("{$fila->name} {$fila->apellidos}"),
                'vencidas' => (int) $fila->vencidas,
            ]);
    }
}
