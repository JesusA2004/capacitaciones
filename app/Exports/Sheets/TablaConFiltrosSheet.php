<?php

namespace App\Exports\Sheets;

use App\Support\Export\Excel\ExcelReportStyler;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja con encabezado de marca, los filtros con que se generó (auditable:
 * quien abre el archivo sabe exactamente qué incluye) y la tabla estilizada.
 *
 * Filas: 1 título, 2 «Generado el…», 3 en blanco, luego una fila por filtro
 * («Filtro | valor»), una en blanco y la tabla.
 */
final class TablaConFiltrosSheet implements FromArray, WithStyles, WithTitle
{
    /**
     * @param  list<array{etiqueta: string, valor: string}>  $filtros
     * @param  list<string>  $columnas
     * @param  list<list<string|int|float|null>>  $filas
     */
    public function __construct(
        private readonly string $titulo,
        private readonly array $filtros,
        private readonly array $columnas,
        private readonly array $filas,
        private readonly string $hojaTitulo,
    ) {}

    public function title(): string
    {
        return mb_substr($this->hojaTitulo, 0, 31);
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        $filas = [[$this->titulo], [''], ['']];

        foreach ($this->filtros as $filtro) {
            $filas[] = [$filtro['etiqueta'], $filtro['valor']];
        }

        $filas[] = [''];

        return [...$filas, $this->columnas, ...$this->filas];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        ExcelReportStyler::encabezadoHoja($sheet, $this->titulo, count($this->columnas));

        $primeraFiltro = 4;
        $ultimaFiltro = $primeraFiltro + count($this->filtros) - 1;

        if ($this->filtros !== []) {
            $sheet->getStyle(sprintf('A%d:A%d', $primeraFiltro, $ultimaFiltro))->getFont()->setBold(true);
        }

        ExcelReportStyler::estilizarTabla($sheet, $ultimaFiltro + 2, count($this->columnas), count($this->filas));

        return [];
    }
}
