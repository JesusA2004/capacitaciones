<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export genérico para cualquier reporte de App\Services\Reportes\ReportesRhService:
 * la tabla {columnas, filas} ya viene calculada por el servicio (misma
 * consulta que ve la pantalla), este export solo la sirve como XLSX.
 */
class ReporteRhExport implements FromArray, WithHeadings, WithTitle
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
     * @return array<int, array<int, string|int|float|null>>
     */
    public function array(): array
    {
        return $this->filas;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->columnas;
    }

    public function title(): string
    {
        return mb_substr($this->titulo, 0, 31);
    }
}
