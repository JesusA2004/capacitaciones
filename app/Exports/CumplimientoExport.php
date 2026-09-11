<?php

namespace App\Exports;

use App\Exports\Sheets\ReporteDetalleSheet;
use App\Exports\Sheets\ReporteResumenSheet;
use App\Models\User;
use App\Services\Reportes\ReporteCumplimientoService;
use App\Support\Export\ChartData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Misma consulta que la pantalla de reporte (ReporteCumplimientoService):
 * hoja "Resumen" con las tarjetas KPI y las gráficas nativas de
 * cumplimiento por sucursal/departamento (reutiliza
 * cumplimientoPorSucursal()/cumplimientoPorDepartamento(), las mismas que ya
 * alimentan MetricasDashboardService, para no duplicar la consulta) + hoja
 * "Detalle" por colaborador. El aislamiento por sucursal se hereda
 * automáticamente porque el servicio ya aplica AlcanceOrganizacionalService.
 */
class CumplimientoExport implements WithMultipleSheets
{
    /**
     * @param  array{sucursal_id?: int|string|null, departamento_id?: int|string|null, curso_id?: int|string|null}  $filtros
     */
    public function __construct(
        private readonly ReporteCumplimientoService $service,
        private readonly User $usuarioActual,
        private readonly array $filtros,
    ) {}

    /**
     * @return array<int, FromArray>
     */
    public function sheets(): array
    {
        $colaboradores = $this->service->todosLosColaboradores($this->usuarioActual, $this->filtros);

        return [
            new ReporteResumenSheet('Reporte de cumplimiento', $this->kpis(), $this->graficas()),
            new ReporteDetalleSheet(
                ['Colaborador', 'Sucursal', 'Departamento', 'Asignadas', 'Completadas', 'Vencidas', 'Cumplimiento (%)'],
                $this->filasDetalle($colaboradores),
            ),
        ];
    }

    /**
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    private function kpis(): array
    {
        $resumen = $this->service->resumenGeneral($this->usuarioActual);

        return [
            ['etiqueta' => 'Asignaciones', 'valor' => (string) $resumen['total_asignaciones']],
            ['etiqueta' => 'Completadas', 'valor' => (string) $resumen['completadas']],
            ['etiqueta' => 'Vencidas', 'valor' => (string) $resumen['vencidas']],
            ['etiqueta' => 'Cumplimiento', 'valor' => number_format($resumen['porcentaje_cumplimiento'], 1).'%'],
        ];
    }

    /**
     * @return array<int, array{titulo: string, datos: ChartData}>
     */
    private function graficas(): array
    {
        $graficas = [];

        $porSucursal = ChartData::fromTable(
            ['Sucursal', 'Cumplimiento %'],
            $this->service->cumplimientoPorSucursal($this->usuarioActual)->map(fn (array $f) => [$f['sucursal'], $f['porcentaje']])->all(),
        );

        if ($porSucursal !== null) {
            $graficas[] = ['titulo' => 'Cumplimiento por sucursal', 'datos' => $porSucursal];
        }

        $porDepartamento = ChartData::fromTable(
            ['Departamento', 'Cumplimiento %'],
            $this->service->cumplimientoPorDepartamento($this->usuarioActual)->map(fn (array $f) => [$f['departamento'], $f['porcentaje']])->all(),
        );

        if ($porDepartamento !== null) {
            $graficas[] = ['titulo' => 'Cumplimiento por departamento', 'datos' => $porDepartamento];
        }

        return $graficas;
    }

    /**
     * @param  Collection<int, User>  $colaboradores
     * @return array<int, array<int, string|int>>
     */
    private function filasDetalle(Collection $colaboradores): array
    {
        return $colaboradores->map(function (User $usuario) {
            $total = (int) $usuario->getAttribute('asignaciones_total');
            $completadas = (int) $usuario->getAttribute('asignaciones_completadas');
            $vencidas = (int) $usuario->getAttribute('asignaciones_vencidas');

            return [
                trim("{$usuario->name} {$usuario->apellidos}"),
                $usuario->sucursalPrincipal->nombre ?? '—',
                $usuario->departamento->nombre ?? '—',
                $total,
                $completadas,
                $vencidas,
                $total > 0 ? (int) round(($completadas / $total) * 100) : 0,
            ];
        })->all();
    }
}
