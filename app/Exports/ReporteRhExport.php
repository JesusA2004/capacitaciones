<?php

namespace App\Exports;

use App\Exports\Sheets\ReporteDetalleSheet;
use App\Exports\Sheets\ReporteResumenSheet;
use App\Support\Export\ChartData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export genérico para cualquier reporte de App\Services\Reportes\ReportesRhService:
 * la tabla {columnas, filas} ya viene calculada por el servicio (misma
 * consulta que ve la pantalla), este export arma un workbook de dos hojas —
 * "Resumen" (tarjetas KPI + gráfica nativa, derivadas automáticamente de la
 * tabla vía ChartData::fromTable()) y "Detalle" (la tabla completa,
 * estilizada) — sin que ninguno de los 9 controladores que lo usan tenga que
 * cambiar: el constructor público es el mismo de siempre.
 */
class ReporteRhExport implements WithMultipleSheets
{
    /**
     * @param  array<int, string>  $columnas
     * @param  array<int, array<int, string|int|float|null>>  $filas
     */
    public function __construct(
        private readonly string $titulo,
        private readonly array $columnas,
        private readonly array $filas,
    ) {}

    /**
     * @return array<int, FromArray>
     */
    public function sheets(): array
    {
        $hojas = [];

        if (count($this->filas) > 0) {
            $hojas[] = new ReporteResumenSheet($this->titulo, $this->kpis(), $this->bloquesGrafica());
        }

        $hojas[] = new ReporteDetalleSheet($this->columnas, $this->filas);

        return $hojas;
    }

    /**
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    private function kpis(): array
    {
        $kpis = [['etiqueta' => 'Registros', 'valor' => (string) count($this->filas)]];

        $chartData = ChartData::fromTable($this->columnas, $this->filas);

        if ($chartData === null || count($chartData->series) !== 1) {
            return $kpis;
        }

        $valores = $chartData->series[0]['valores'];
        $kpis[] = ['etiqueta' => 'Total general', 'valor' => (string) array_sum($valores)];

        if ($valores !== []) {
            $indiceMax = array_keys($valores, max($valores))[0];
            $kpis[] = ['etiqueta' => 'Mayor valor', 'valor' => (string) $chartData->categorias[$indiceMax]];
        }

        return $kpis;
    }

    /**
     * @return array<int, array{titulo: string, datos: ChartData}>
     */
    private function bloquesGrafica(): array
    {
        $chartData = ChartData::fromTable($this->columnas, $this->filas);

        return $chartData !== null ? [['titulo' => 'Distribución', 'datos' => $chartData]] : [];
    }
}
