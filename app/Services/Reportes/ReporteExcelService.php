<?php

namespace App\Services\Reportes;

use App\Services\Reportes\Propios\ReportePropioService;
use App\Support\Export\ChartData;
use App\Support\Export\Excel\ExcelChartFactory;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel de «Mis reportes»: hoja «Reporte» con el diseño elegido y, si se pidió
 * gráfica, hoja «Gráfica» con su tabla y una gráfica nativa de Excel. El texto
 * siempre se escribe como texto explícito, de modo que un dato que empiece con
 * = + - @ nunca se interprete como fórmula.
 *
 * @phpstan-import-type Resultado from ReportePropioService
 */
class ReporteExcelService
{
    private const HOJA_GRAFICA = 'Gráfica';

    /**
     * @param  Resultado  $resultado
     */
    public function generar(array $resultado): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Reporte');
        $diseno = $resultado['diseno'];
        $color = $this->colorArgb($diseno['color'] ?? null);
        $columnas = $resultado['columnas'];
        $totalColumnas = max(count($columnas), 1);
        $ultima = Coordinate::stringFromColumnIndex($totalColumnas);
        $fila = 1;

        if ($diseno['encabezado'] ?? true) {
            $hoja->setCellValueExplicit('A1', $resultado['titulo'], DataType::TYPE_STRING);
            $hoja->mergeCells(sprintf('A1:%s1', $ultima));
            $hoja->getStyle('A1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $hoja->getRowDimension(1)->setRowHeight(30);
            $subtitulo = is_string($diseno['subtitulo'] ?? null) && $diseno['subtitulo'] !== '' ? $diseno['subtitulo'].' · ' : '';
            $hoja->setCellValueExplicit('A2', sprintf('%sMR. LANA PEOPLE · Generado el %s · %d registros', $subtitulo, now('America/Mexico_City')->format('d/m/Y H:i'), $resultado['total']), DataType::TYPE_STRING);
            $hoja->mergeCells(sprintf('A2:%s2', $ultima));
            $hoja->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('707874');
            $fila = 3;
        }

        if (($diseno['mostrar_filtros'] ?? true) && $resultado['filtros_texto'] !== []) {
            $hoja->setCellValueExplicit('A'.$fila, 'Filtros: '.implode(' · ', $resultado['filtros_texto']), DataType::TYPE_STRING);
            $hoja->mergeCells(sprintf('A%d:%s%d', $fila, $ultima, $fila));
            $hoja->getStyle('A'.$fila)->getFont()->setSize(9)->getColor()->setRGB('225C54');
            $fila++;
        }

        foreach ($resultado['avisos'] as $aviso) {
            $hoja->setCellValueExplicit('A'.$fila, $aviso, DataType::TYPE_STRING);
            $hoja->mergeCells(sprintf('A%d:%s%d', $fila, $ultima, $fila));
            $hoja->getStyle('A'.$fila)->getFont()->setSize(9)->getColor()->setRGB('754711');
            $fila++;
        }

        $filaEncabezado = $fila;

        foreach ($columnas as $i => $columna) {
            $hoja->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$filaEncabezado, $columna['etiqueta'], DataType::TYPE_STRING);
        }

        $hoja->getStyle(sprintf('A%d:%s%d', $filaEncabezado, $ultima, $filaEncabezado))->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getRowDimension($filaEncabezado)->setRowHeight(22);

        foreach ($resultado['filas'] as $n => $renglon) {
            $f = $filaEncabezado + 1 + $n;

            foreach ($renglon as $i => $valor) {
                $this->escribir($hoja, Coordinate::stringFromColumnIndex($i + 1).$f, $valor, $columnas[$i]['tipo'] ?? 'texto');
            }

            if ($n % 2 === 1) {
                $hoja->getStyle(sprintf('A%d:%s%d', $f, $ultima, $f))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FBF9F4');
            }
        }

        $ultimaFilaDatos = $filaEncabezado + count($resultado['filas']);

        if ($resultado['totales'] !== null && $resultado['filas'] !== []) {
            $filaTotales = $ultimaFilaDatos + 1;

            foreach ($resultado['totales'] as $i => $valor) {
                $this->escribir($hoja, Coordinate::stringFromColumnIndex($i + 1).$filaTotales, $valor, is_string($valor) ? 'texto' : ($columnas[$i]['tipo'] ?? 'numero'));
            }

            $hoja->getStyle(sprintf('A%d:%s%d', $filaTotales, $ultima, $filaTotales))->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3EDE1']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $color]]],
            ]);
        }

        $hoja->getStyle(sprintf('A%d:%s%d', $filaEncabezado, $ultima, $ultimaFilaDatos))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E7DED1']]],
        ]);

        if ($resultado['filas'] !== []) {
            $hoja->setAutoFilter(sprintf('A%d:%s%d', $filaEncabezado, $ultima, $ultimaFilaDatos));
        }

        $hoja->freezePane('A'.($filaEncabezado + 1));

        for ($c = 1; $c <= $totalColumnas; $c++) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $orientacion = ($diseno['orientacion'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $hoja->getPageSetup()->setOrientation($orientacion)->setFitToWidth(1)->setFitToHeight(0);

        if ($resultado['grafica'] !== null) {
            $this->agregarGrafica($libro, $resultado['grafica']);
        }

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    public function escritor(Spreadsheet $libro): Xlsx
    {
        $escritor = new Xlsx($libro);
        $escritor->setIncludeCharts(true);

        return $escritor;
    }

    /**
     * @param  array{tipo: string, titulo: string, nombre: string, categorias: list<string>, valores: list<float>}  $grafica
     */
    private function agregarGrafica(Spreadsheet $libro, array $grafica): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle(self::HOJA_GRAFICA);
        $tipo = match ($grafica['tipo']) {
            'pastel' => 'distribucion',
            'lineas' => 'tiempo',
            default => 'barras',
        };

        $hoja->setCellValueExplicit('A1', 'Categoría', DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('B1', $grafica['nombre'], DataType::TYPE_STRING);
        $hoja->getStyle('A1:B1')->getFont()->setBold(true);

        foreach ($grafica['categorias'] as $i => $categoria) {
            $hoja->setCellValueExplicit('A'.($i + 2), $categoria, DataType::TYPE_STRING);
            $hoja->setCellValue('B'.($i + 2), $grafica['valores'][$i]);
        }

        $hoja->getColumnDimension('A')->setAutoSize(true);
        $hoja->getColumnDimension('B')->setAutoSize(true);

        $datos = ChartData::deSerie($tipo, $grafica['categorias'], $grafica['nombre'], $grafica['valores']);
        $hoja->addChart(ExcelChartFactory::construir($datos, self::HOJA_GRAFICA, 'A2', 'D1', 'N24', $grafica['titulo']));
    }

    private function escribir(Worksheet $hoja, string $celda, string|int|float|null $valor, string $tipo): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        if ($tipo === 'fecha') {
            $hoja->setCellValue($celda, Date::dateTimeToExcel(CarbonImmutable::parse((string) $valor)));
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('dd/mm/yyyy');

            return;
        }

        if (in_array($tipo, ['numero', 'moneda'], true) && is_numeric($valor)) {
            $hoja->setCellValueExplicit($celda, (float) $valor, DataType::TYPE_NUMERIC);

            if ($tipo === 'moneda') {
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('"$"#,##0.00');
            }

            return;
        }

        $hoja->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
    }

    private function colorArgb(mixed $token): string
    {
        $colores = (array) config('reportes.colores', []);
        $elegido = is_string($token) && isset($colores[$token]) ? $colores[$token] : ($colores['petroleo'] ?? []);

        return ltrim(is_array($elegido) && is_string($elegido['hex'] ?? null) ? $elegido['hex'] : '#0D3E43', '#');
    }
}
