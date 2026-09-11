<?php

namespace App\Support\Export\Excel;

use App\Support\Export\ChartData;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

/**
 * Construye gráficas NATIVAS de Excel (editables por el usuario, no
 * imágenes) a partir de App\Support\Export\ChartData. No escribe celdas: los
 * exports (ver App\Exports\Sheets\*) escriben la tabla de datos que respalda
 * la gráfica a través de FromArray (el mecanismo normal de Maatwebsite\Excel,
 * que garantiza que las celdas ya existen antes de que se serialice el
 * archivo) y esta clase sólo referencia esas celdas por rango — necesita
 * saber en qué celda empieza la columna de categorías para derivar el resto
 * del layout (encabezado = una fila arriba, columnas de serie a la derecha).
 */
final class ExcelChartFactory
{
    /**
     * @param  string  $celdaCategoriaInicio  ej. "A8": primera fila de DATOS
     *                                        (no el encabezado) de la columna de categorías.
     * @param  string  $anclaSuperior  ej. "A1" (celda donde inicia la gráfica en el lienzo)
     * @param  string  $anclaInferior  ej. "F18" (celda donde termina)
     */
    public static function construir(
        ChartData $datos,
        string $nombreHoja,
        string $celdaCategoriaInicio,
        string $anclaSuperior,
        string $anclaInferior,
        string $titulo,
    ): Chart {
        [$colCategoria, $filaDatosInicioBruta] = Coordinate::coordinateFromString($celdaCategoriaInicio);
        $filaDatosInicio = (int) $filaDatosInicioBruta;
        $filaEncabezado = $filaDatosInicio - 1;
        $filaDatosFin = $filaDatosInicio + count($datos->categorias) - 1;
        $colCategoriaIndice = Coordinate::columnIndexFromString($colCategoria);

        $dataSeriesLabels = [];
        $dataSeriesValues = [];

        foreach ($datos->series as $s => $serie) {
            $colSerie = Coordinate::stringFromColumnIndex($colCategoriaIndice + 1 + $s);

            $dataSeriesLabels[$s] = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$nombreHoja}'!\${$colSerie}\${$filaEncabezado}",
                null,
                1,
            );

            $dataSeriesValues[$s] = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$nombreHoja}'!\${$colSerie}\${$filaDatosInicio}:\${$colSerie}\${$filaDatosFin}",
                null,
                count($serie['valores']),
                [],
                null,
                ExcelReportStyler::colorSerie($s),
            );
        }

        $dataSeriesCategories = [
            0 => new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$nombreHoja}'!\${$colCategoria}\${$filaDatosInicio}:\${$colCategoria}\${$filaDatosFin}",
                null,
                count($datos->categorias),
            ),
        ];

        [$tipo, $agrupacion] = match ($datos->tipo) {
            'distribucion' => [DataSeries::TYPE_DOUGHNUTCHART, null],
            'tiempo' => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD],
            default => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED],
        };

        $serieGrafica = new DataSeries(
            $tipo,
            $agrupacion,
            array_keys($dataSeriesValues),
            $dataSeriesLabels,
            $dataSeriesCategories,
            $dataSeriesValues,
        );

        $plotArea = new PlotArea(null, [$serieGrafica]);
        $leyenda = new Legend(Legend::POSITION_BOTTOM, null, false);

        $chart = new Chart('grafica-'.uniqid(), new Title($titulo), $leyenda, $plotArea);
        $chart->setTopLeftPosition($anclaSuperior);
        $chart->setBottomRightPosition($anclaInferior);

        return $chart;
    }

    /**
     * Filas listas para escribir vía FromArray: encabezado (Categoría +
     * nombre de cada serie) seguido de una fila por categoría. El caller
     * decide en qué fila del sheet arrancan (normalmente después de las
     * tarjetas KPI).
     *
     * @return array<int, array<int, string|float>>
     */
    public static function filasDeDatos(ChartData $datos): array
    {
        $encabezado = ['Categoría', ...array_map(fn (array $serie) => $serie['nombre'], $datos->series)];
        $filas = [$encabezado];

        foreach ($datos->categorias as $i => $categoria) {
            $fila = [$categoria];

            foreach ($datos->series as $serie) {
                $fila[] = $serie['valores'][$i];
            }

            $filas[] = $fila;
        }

        return $filas;
    }
}
