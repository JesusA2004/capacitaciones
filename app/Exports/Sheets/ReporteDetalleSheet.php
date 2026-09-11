<?php

namespace App\Exports\Sheets;

use App\Support\Export\Excel\ExcelReportStyler;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Detalle" reutilizable: tabla completa {columnas, filas} con estilo
 * de marca (ver App\Support\Export\Excel\ExcelReportStyler). Misma tabla que
 * ya ve la pantalla — nunca una consulta aparte.
 */
final class ReporteDetalleSheet implements FromArray, WithStyles, WithTitle
{
    /**
     * @param  array<int, string>  $columnas
     * @param  array<int, array<int, string|int|float|null>>  $filas
     */
    public function __construct(
        private readonly array $columnas,
        private readonly array $filas,
        private readonly string $hojaTitulo = 'Detalle',
    ) {}

    public function title(): string
    {
        return mb_substr($this->hojaTitulo, 0, 31);
    }

    /**
     * @return array<int, array<int, string|int|float|null>>
     */
    public function array(): array
    {
        return [$this->columnas, ...$this->filas];
    }

    public function styles(Worksheet $sheet)
    {
        ExcelReportStyler::estilizarTabla($sheet, 1, count($this->columnas), count($this->filas));

        return [];
    }
}
