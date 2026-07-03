<?php

namespace App\Services\Reportes;

use App\Enums\EstadoIntentoCuestionario;
use App\Enums\EstadoProgreso;
use App\Enums\EstadoSesionEnVivo;
use App\Models\EntregaActividad;
use App\Models\IntentoCuestionario;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Support\Collection;

/**
 * Metricas resumidas para los tres dashboards por rol. Deliberadamente no
 * reutiliza ReporteCumplimientoService::resumenGeneral() como unico dato:
 * un dashboard necesita ademas "cosas por hacer ahora mismo" (calificar,
 * asistir a una sesion), no solo el cumplimiento historico.
 */
class MetricasDashboardService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ReporteCumplimientoService $reporteCumplimiento,
    ) {}

    /**
     * @return array{cursosEnProgreso: int, cursosCompletados: int, proximasSesiones: Collection<int, SesionEnVivo>}
     */
    public function paraColaborador(User $usuario): array
    {
        $inscripciones = $usuario->inscripcionesCurso();

        return [
            'cursosEnProgreso' => (clone $inscripciones)->where('estado', EstadoProgreso::EnProgreso->value)->count()
                + (clone $inscripciones)->where('estado', EstadoProgreso::Pendiente->value)->count(),
            'cursosCompletados' => (clone $inscripciones)->where('estado', EstadoProgreso::Completada->value)->count(),
            'proximasSesiones' => $this->proximasSesionesDeUsuario($usuario),
        ];
    }

    /**
     * @return array{resumen: array{total_asignaciones: int, completadas: int, vencidas: int, porcentaje_cumplimiento: float}, cumplimientoPorSucursal: Collection<int, array{sucursal_id: int, sucursal: string, total: int, completadas: int, porcentaje: float}>, pendientesCalificar: int, proximasSesiones: Collection<int, SesionEnVivo>}
     */
    public function paraSucursal(User $usuario): array
    {
        return [
            'resumen' => $this->reporteCumplimiento->resumenGeneral($usuario),
            'cumplimientoPorSucursal' => $this->reporteCumplimiento->cumplimientoPorSucursal($usuario),
            'pendientesCalificar' => $this->pendientesDeCalificar($usuario),
            'proximasSesiones' => $this->proximasSesionesDeSucursal($usuario),
        ];
    }

    /**
     * @return array{resumen: array{total_asignaciones: int, completadas: int, vencidas: int, porcentaje_cumplimiento: float}, cumplimientoPorSucursal: Collection<int, array{sucursal_id: int, sucursal: string, total: int, completadas: int, porcentaje: float}>}
     */
    public function global(User $usuario): array
    {
        return [
            'resumen' => $this->reporteCumplimiento->resumenGeneral($usuario),
            'cumplimientoPorSucursal' => $this->reporteCumplimiento->cumplimientoPorSucursal($usuario),
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
}
