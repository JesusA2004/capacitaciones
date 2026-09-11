<?php

namespace App\Exports;

use App\Exports\Sheets\ReporteResumenSheet;
use App\Support\Export\ChartData;
use Illuminate\Support\Collection;
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
            $this->agregarBloque($bloques, $columna, $titulo, $this->paresEtiquetaValor($g[$clave]));
        }

        $this->agregarBloque($bloques, 'Estado', 'Expedientes por estado', $this->paresEtiquetaValor($g['expedientesEstado']));
        $this->agregarBloque($bloques, 'Estado', 'Documentos por estado', $this->paresEtiquetaValor($g['documentosPorEstado']));
        $this->agregarBloque($bloques, 'Módulo', 'Reclutamiento y solicitudes', $this->paresEtiquetaValor($this->otrosModulos));

        return $bloques;
    }

    /**
     * `MetricasRhDashboardService::global()['graficas']` mezcla Collections
     * (de agruparPor()) y arrays planos según la clave — nunca hacer
     * `(array) $collection`: corrompe su estructura interna en vez de
     * convertir sus elementos. `collect()` acepta ambas formas por igual.
     *
     * @param  Collection<int, array{etiqueta: string, valor: int}>|array<int, array{etiqueta: string, valor: int}>  $datos
     * @return array<int, array{0: string, 1: int}>
     */
    private function paresEtiquetaValor(Collection|array $datos): array
    {
        /** @var Collection<int, array{etiqueta: string, valor: int}> $coleccion */
        $coleccion = $datos instanceof Collection ? $datos : collect($datos);

        return $coleccion->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all();
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
