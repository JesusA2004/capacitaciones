<?php

namespace App\Exports\Sheets;

use App\Support\Export\ChartData;
use App\Support\Export\Excel\ExcelChartFactory;
use App\Support\Export\Excel\ExcelReportStyler;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Resumen" reutilizable: título + hasta 4 tarjetas KPI + uno o más
 * bloques de gráfica nativa (tabla de datos + Chart), cada uno con su propio
 * subtítulo. Usada por App\Exports\ReporteRhExport, CumplimientoExport y
 * ReporteGeneralExport — mismo layout para que todos los workbooks de
 * reportes se sientan iguales.
 *
 * El orden de llamadas de Maatwebsite\Excel es siempre: array() puebla las
 * celdas -> luego WithStyles/WithCharts se invocan sobre ese estado ya
 * escrito (ver Maatwebsite\Excel\Sheet::close()), así que charts()/styles()
 * pueden confiar en que $this->bloques (calculado dentro de array()) ya
 * existe. array() reconstruye $this->bloques desde cero en cada llamada
 * para ser seguro aunque el framework lo invoque más de una vez.
 */
final class ReporteResumenSheet implements FromArray, WithCharts, WithStyles, WithTitle
{
    private const COL_GRAFICA_INICIO = 6; // F

    private const ANCHO_GRAFICA_COLS = 8; // F..N

    private const ALTO_GRAFICA_FILAS = 15;

    private const FILA_KPI = 4;

    /** @var array<int, array{fila: int}> */
    private array $bloques = [];

    /**
     * @param  array<int, array{etiqueta: string, valor: string}>  $kpis  máx. 4
     * @param  array<int, array{titulo: string, datos: ChartData}>  $graficas
     */
    public function __construct(
        private readonly string $titulo,
        private readonly array $kpis,
        private readonly array $graficas,
        private readonly string $hojaTitulo = 'Resumen',
    ) {}

    public function title(): string
    {
        return mb_substr($this->hojaTitulo, 0, 31);
    }

    /**
     * @return array<int, array<int, string|float>>
     */
    public function array(): array
    {
        $this->bloques = [];

        $filas = [
            [$this->titulo],
            ['MR. LANA PEOPLE — Generado el '.now()->format('d/m/Y H:i')],
            [],
            array_map(fn (array $kpi) => mb_strtoupper((string) $kpi['etiqueta']), $this->kpis),
            array_map(fn (array $kpi) => (string) $kpi['valor'], $this->kpis),
            [],
        ];

        $filaActual = count($filas) + 1;

        foreach ($this->graficas as $bloque) {
            $this->bloques[] = ['fila' => $filaActual];

            $filas[] = [$bloque['titulo']];
            $filasDatos = ExcelChartFactory::filasDeDatos($bloque['datos']);
            array_push($filas, ...$filasDatos);
            $filas[] = [];

            $filaActual += 1 + count($filasDatos) + 1;
        }

        return $filas;
    }

    /**
     * @return array<int, Chart>
     */
    public function charts(): array
    {
        $charts = [];

        foreach ($this->graficas as $i => $bloque) {
            $filaTitulo = $this->bloques[$i]['fila'];
            $filaPrimeraFilaDatos = $filaTitulo + 2;

            $charts[] = ExcelChartFactory::construir(
                $bloque['datos'],
                $this->hojaTitulo,
                'A'.$filaPrimeraFilaDatos,
                self::columna(self::COL_GRAFICA_INICIO).$filaTitulo,
                self::columna(self::COL_GRAFICA_INICIO + self::ANCHO_GRAFICA_COLS).($filaTitulo + self::ALTO_GRAFICA_FILAS),
                $bloque['titulo'],
            );
        }

        return $charts;
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => ExcelReportStyler::COLOR_MARCA_OSCURO]],
        ]);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => ExcelReportStyler::COLOR_TEXTO_TENUE]],
        ]);

        foreach ($this->kpis as $i => $kpi) {
            $col = ($i * 2) + 1;
            $colLetra = self::columna($col);
            $colFinLetra = self::columna($col + 1);
            $filaValor = self::FILA_KPI + 1;

            $sheet->mergeCells("{$colLetra}".self::FILA_KPI.":{$colFinLetra}".self::FILA_KPI);
            $sheet->mergeCells("{$colLetra}{$filaValor}:{$colFinLetra}{$filaValor}");

            $sheet->getStyle("{$colLetra}".self::FILA_KPI)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => ExcelReportStyler::COLOR_TEXTO_TENUE]],
            ]);
            $sheet->getStyle("{$colLetra}{$filaValor}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => ExcelReportStyler::COLOR_MARCA_OSCURO]],
            ]);
            $sheet->getStyle("{$colLetra}".self::FILA_KPI.":{$colLetra}{$filaValor}")->applyFromArray([
                'borders' => ['left' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => ExcelReportStyler::colorSerie($i)]]],
            ]);
        }

        foreach ($this->bloques as $bloque) {
            $sheet->getStyle('A'.$bloque['fila'])->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => ExcelReportStyler::COLOR_MARCA_OSCURO]],
            ]);
            $filaEncabezado = $bloque['fila'] + 1;
            $sheet->getStyle("A{$filaEncabezado}:D{$filaEncabezado}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ExcelReportStyler::COLOR_ZEBRA]],
            ]);
        }

        foreach (range(1, self::COL_GRAFICA_INICIO + self::ANCHO_GRAFICA_COLS) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize($c < self::COL_GRAFICA_INICIO);
        }

        return [];
    }

    private static function columna(int $indice): string
    {
        return ExcelReportStyler::columnaLetra($indice);
    }
}
