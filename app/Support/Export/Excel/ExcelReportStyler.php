<?php

namespace App\Support\Export\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Estilo de marca compartido por todas las hojas Excel de reportes: mismo
 * criterio visual (colores, tipografía, bandas) que
 * App\Support\Export\Pdf\ChartRenderer usa para el PDF, para que ambos
 * formatos se sientan parte del mismo sistema.
 */
final class ExcelReportStyler
{
    public const COLOR_MARCA = '64D64B';

    public const COLOR_MARCA_OSCURO = '274754';

    public const COLOR_ZEBRA = 'F3F4F6';

    public const COLOR_TEXTO_TENUE = '6B7280';

    public const COLOR_BORDE = 'E5E7EB';

    /** @var array<int, string> */
    public const PALETA = ['64D64B', '2DC7D3', '274754', 'E9C468', 'F4A462'];

    /**
     * Encabezado con relleno de marca + texto blanco, bandas alternas,
     * bordes suaves, autofiltro, freeze pane bajo el encabezado y
     * auto-ancho de columnas.
     */
    public static function estilizarTabla(Worksheet $hoja, int $filaEncabezado, int $totalColumnas, int $totalFilasDatos): void
    {
        $ultimaColumna = self::columnaLetra($totalColumnas);
        $rangoEncabezado = "A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}";

        $hoja->getStyle($rangoEncabezado)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_MARCA_OSCURO]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getRowDimension($filaEncabezado)->setRowHeight(22);

        for ($fila = $filaEncabezado + 1; $fila <= $filaEncabezado + $totalFilasDatos; $fila++) {
            if (($fila - $filaEncabezado) % 2 === 0) {
                $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_ZEBRA]],
                ]);
            }
        }

        $rangoCompleto = "A{$filaEncabezado}:{$ultimaColumna}".($filaEncabezado + $totalFilasDatos);
        $hoja->getStyle($rangoCompleto)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDE]]],
        ]);

        if ($totalFilasDatos > 0) {
            $hoja->setAutoFilter($rangoCompleto);
        }

        $hoja->freezePane('A'.($filaEncabezado + 1));

        for ($c = 1; $c <= $totalColumnas; $c++) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    /**
     * Título grande + timestamp de generación, en la fila 1-2 de la hoja.
     */
    public static function encabezadoHoja(Worksheet $hoja, string $titulo, int $columnasParaMerge): void
    {
        $ultima = self::columnaLetra(max($columnasParaMerge, 2));

        $hoja->setCellValue('A1', $titulo);
        $hoja->mergeCells("A1:{$ultima}1");
        $hoja->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => self::COLOR_MARCA_OSCURO]],
        ]);
        $hoja->getRowDimension(1)->setRowHeight(28);

        $hoja->setCellValue('A2', 'MR. LANA PEOPLE — Generado el '.now()->format('d/m/Y H:i'));
        $hoja->mergeCells("A2:{$ultima}2");
        $hoja->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => self::COLOR_TEXTO_TENUE]],
        ]);
    }

    /**
     * Tarjeta KPI: etiqueta pequeña arriba, valor grande abajo, con un
     * borde izquierdo de color. Ocupa 2 columnas x 2 filas a partir de
     * $columnaInicio/$filaInicio.
     */
    public static function tarjetaKpi(Worksheet $hoja, int $columnaInicio, int $filaInicio, string $etiqueta, string $valor, string $colorHex = self::COLOR_MARCA): void
    {
        $colInicioLetra = self::columnaLetra($columnaInicio);
        $colFinLetra = self::columnaLetra($columnaInicio + 1);
        $filaValor = $filaInicio + 1;

        $hoja->setCellValue("{$colInicioLetra}{$filaInicio}", mb_strtoupper($etiqueta));
        $hoja->mergeCells("{$colInicioLetra}{$filaInicio}:{$colFinLetra}{$filaInicio}");
        $hoja->getStyle("{$colInicioLetra}{$filaInicio}")->applyFromArray([
            'font' => ['size' => 9, 'bold' => true, 'color' => ['rgb' => self::COLOR_TEXTO_TENUE]],
        ]);

        $hoja->setCellValue("{$colInicioLetra}{$filaValor}", $valor);
        $hoja->mergeCells("{$colInicioLetra}{$filaValor}:{$colFinLetra}{$filaValor}");
        $hoja->getStyle("{$colInicioLetra}{$filaValor}")->applyFromArray([
            'font' => ['size' => 18, 'bold' => true, 'color' => ['rgb' => self::COLOR_MARCA_OSCURO]],
        ]);
        $hoja->getRowDimension($filaValor)->setRowHeight(26);

        $hoja->getStyle("{$colInicioLetra}{$filaInicio}:{$colFinLetra}{$filaValor}")->applyFromArray([
            'borders' => ['left' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => $colorHex]]],
        ]);
    }

    public static function colorSerie(int $indice): string
    {
        return self::PALETA[$indice % count(self::PALETA)];
    }

    public static function columnaLetra(int $numero): string
    {
        return Coordinate::stringFromColumnIndex($numero);
    }
}
