<?php

namespace App\Exports;

use App\Exports\Sheets\ReporteResumenSheet;
use App\Support\Export\ChartData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Workbook de una hoja para el hub de /reportes: tarjetas KPI + todas las
 * gráficas de App\Http\Controllers\Reportes\ReporteGeneralController en un
 * solo "Resumen" nativo de Excel — el mismo snapshot que ve la pantalla.
 */
class ReporteGeneralExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $metricas  resultado de MetricasRhDashboardService::global()
     * @param  array<int, array{etiqueta: string, valor: int}>  $otrosModulos
     */
    public function __construct(
        private readonly array $metricas,
        private readonly array $otrosModulos,
    ) {}

    /**
     * @return array<int, FromArray>
     */
    public function sheets(): array
    {
        return [
            new ReporteResumenSheet('Reporte general — Portal RH', $this->kpis(), $this->graficas()),
        ];
    }

    /**
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    private function kpis(): array
    {
        $cards = $this->metricas['cards'];

        return [
            ['etiqueta' => 'Colaboradores activos', 'valor' => (string) $cards['colaboradores_activos']],
            ['etiqueta' => 'Bajas del mes', 'valor' => (string) $cards['bajas_del_mes']],
            ['etiqueta' => 'Expedientes completos', 'valor' => (string) $cards['expedientes_completos']],
            ['etiqueta' => 'Documentos pendientes', 'valor' => (string) $cards['documentos_pendientes']],
        ];
    }

    /**
     * @return array<int, array{titulo: string, datos: ChartData}>
     */
    private function graficas(): array
    {
        $g = $this->metricas['graficas'];
        $bloques = [];

        $porGrupo = [
            'colaboradoresPorSucursal' => ['Sucursal', 'Colaboradores por sucursal'],
            'colaboradoresPorDepartamento' => ['Departamento', 'Colaboradores por departamento'],
            'colaboradoresPorPuesto' => ['Puesto', 'Colaboradores por puesto'],
        ];

        foreach ($porGrupo as $clave => [$columna, $titulo]) {
            $this->agregarBloque($bloques, $columna, $titulo, collect((array) $g[$clave])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all());
        }

        $this->agregarBloque($bloques, 'Estado', 'Expedientes por estado', collect((array) $g['expedientesEstado'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all());
        $this->agregarBloque($bloques, 'Estado', 'Documentos por estado', collect((array) $g['documentosPorEstado'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all());
        $this->agregarBloque($bloques, 'Módulo', 'Reclutamiento y solicitudes', collect($this->otrosModulos)->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all());

        return $bloques;
    }

    /**
     * @param  array<int, array{titulo: string, datos: ChartData}>  $bloques
     * @param  array<int, array<int, string|int|float|null>>  $filas
     */
    private function agregarBloque(array &$bloques, string $columna, string $titulo, array $filas): void
    {
        $chart = ChartData::fromTable([$columna, 'Total'], $filas);

        if ($chart !== null) {
            $bloques[] = ['titulo' => $titulo, 'datos' => $chart];
        }
    }
}
