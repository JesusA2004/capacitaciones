<?php

namespace App\Services\Reportes;

use App\Enums\EstadoAsignacion;
use App\Models\AsignacionUsuario;
use App\Models\Curso;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reporte de cumplimiento de capacitacion por colaborador: cuantas
 * asignaciones tiene, cuantas completo, cuantas siguen pendientes/vencidas
 * y su porcentaje de cumplimiento. Reutiliza AlcanceOrganizacionalService
 * (Fase 1) para que un gerente/supervisor de sucursal solo vea sus propios
 * colaboradores, igual que en el resto del sistema.
 */
class ReporteCumplimientoService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @param  array{sucursal_id?: int|string|null, departamento_id?: int|string|null, curso_id?: int|string|null}  $filtros
     * @return LengthAwarePaginator<int, User>
     */
    public function porColaborador(User $usuarioActual, array $filtros): LengthAwarePaginator
    {
        return $this->consultaColaboradores($usuarioActual, $filtros)->paginate(20)->withQueryString();
    }

    /**
     * Misma consulta que porColaborador(), sin paginar: la usa
     * App\Exports\CumplimientoExport para no repetir el aislamiento por
     * sucursal ni los filtros en dos lugares distintos.
     *
     * @param  array{sucursal_id?: int|string|null, departamento_id?: int|string|null, curso_id?: int|string|null}  $filtros
     * @return Collection<int, User>
     */
    public function todosLosColaboradores(User $usuarioActual, array $filtros): Collection
    {
        return $this->consultaColaboradores($usuarioActual, $filtros)->get();
    }

    /**
     * @param  array{sucursal_id?: int|string|null, departamento_id?: int|string|null, curso_id?: int|string|null}  $filtros
     * @return Builder<User>
     */
    private function consultaColaboradores(User $usuarioActual, array $filtros): Builder
    {
        $query = User::query()
            ->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre'])
            ->withCount([
                'asignacionesUsuario as asignaciones_total',
                'asignacionesUsuario as asignaciones_completadas' => fn ($q) => $q->where('estado', EstadoAsignacion::Completada->value),
                'asignacionesUsuario as asignaciones_vencidas' => fn ($q) => $q->where('estado', EstadoAsignacion::Vencida->value),
            ]);

        $query = $this->alcance->limitarUsuariosPorAlcance($query, $usuarioActual);

        if (! empty($filtros['sucursal_id'])) {
            $query->where('sucursal_principal_id', $filtros['sucursal_id']);
        }

        if (! empty($filtros['departamento_id'])) {
            $query->where('departamento_id', $filtros['departamento_id']);
        }

        if (! empty($filtros['curso_id'])) {
            $cursoId = $filtros['curso_id'];
            $query->whereHas('asignacionesUsuario.asignacion', function ($q) use ($cursoId) {
                $q->where('asignable_type', Curso::class)->where('asignable_id', $cursoId);
            });
        }

        return $query->orderBy('name');
    }

    /**
     * @return array{total_asignaciones: int, completadas: int, vencidas: int, porcentaje_cumplimiento: float}
     */
    public function resumenGeneral(User $usuarioActual): array
    {
        $usuariosVisiblesIds = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuarioActual)->pluck('id');

        $totales = AsignacionUsuario::query()
            ->whereIn('user_id', $usuariosVisiblesIds)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when estado = 'completada' then 1 else 0 end) as completadas")
            ->selectRaw("sum(case when estado = 'vencida' then 1 else 0 end) as vencidas")
            ->first();

        $total = (int) ($totales->total ?? 0);
        $completadas = (int) ($totales->completadas ?? 0);

        return [
            'total_asignaciones' => $total,
            'completadas' => $completadas,
            'vencidas' => (int) ($totales->vencidas ?? 0),
            'porcentaje_cumplimiento' => $total > 0 ? round(($completadas / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return Collection<int, array{sucursal_id: int, sucursal: string, total: int, completadas: int, porcentaje: float}>
     */
    public function cumplimientoPorSucursal(User $usuarioActual): Collection
    {
        $sucursalesIds = $this->alcance->sucursalesVisiblesIds($usuarioActual);

        return DB::table('asignaciones_usuario')
            ->join('users', 'users.id', '=', 'asignaciones_usuario.user_id')
            ->join('sucursales', 'sucursales.id', '=', 'users.sucursal_principal_id')
            ->whereIn('sucursales.id', $sucursalesIds)
            ->selectRaw('sucursales.id as sucursal_id, sucursales.nombre as sucursal, count(*) as total')
            ->selectRaw("sum(case when asignaciones_usuario.estado = 'completada' then 1 else 0 end) as completadas")
            ->groupBy('sucursales.id', 'sucursales.nombre')
            ->orderBy('sucursales.nombre')
            ->get()
            ->map(fn ($fila) => [
                'sucursal_id' => (int) $fila->sucursal_id,
                'sucursal' => (string) $fila->sucursal,
                'total' => (int) $fila->total,
                'completadas' => (int) $fila->completadas,
                'porcentaje' => $fila->total > 0 ? round(($fila->completadas / $fila->total) * 100, 1) : 0.0,
            ]);
    }

    /**
     * Mismo cálculo que cumplimientoPorSucursal(), agrupado por
     * departamento en vez de sucursal, para el dashboard (sección 2 de
     * docs/AUDITORIA_CUMPLIMIENTO.md — fase de modernización visual). El
     * aislamiento sigue siendo por sucursal (no existe un "alcance por
     * departamento" en AlcanceOrganizacionalService): un usuario solo ve el
     * cumplimiento de los departamentos de sus colaboradores visibles.
     *
     * @return Collection<int, array{departamento_id: int, departamento: string, total: int, completadas: int, porcentaje: float}>
     */
    public function cumplimientoPorDepartamento(User $usuarioActual): Collection
    {
        $usuariosVisiblesIds = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuarioActual)->pluck('id');

        return DB::table('asignaciones_usuario')
            ->join('users', 'users.id', '=', 'asignaciones_usuario.user_id')
            ->join('departamentos', 'departamentos.id', '=', 'users.departamento_id')
            ->whereIn('users.id', $usuariosVisiblesIds)
            ->selectRaw('departamentos.id as departamento_id, departamentos.nombre as departamento, count(*) as total')
            ->selectRaw("sum(case when asignaciones_usuario.estado = 'completada' then 1 else 0 end) as completadas")
            ->groupBy('departamentos.id', 'departamentos.nombre')
            ->orderBy('departamentos.nombre')
            ->get()
            ->map(fn ($fila) => [
                'departamento_id' => (int) $fila->departamento_id,
                'departamento' => (string) $fila->departamento,
                'total' => (int) $fila->total,
                'completadas' => (int) $fila->completadas,
                'porcentaje' => $fila->total > 0 ? round(($fila->completadas / $fila->total) * 100, 1) : 0.0,
            ]);
    }
}
