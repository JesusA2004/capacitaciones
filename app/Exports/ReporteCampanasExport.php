<?php

namespace App\Exports;

use App\Exports\Sheets\TablaConFiltrosSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del reporte de campañas de reclutamiento: mismo arreglo que arma
 * App\Services\Reclutamiento\ReporteCampanasService::reporte() para la
 * pantalla y el PDF (nunca una consulta aparte). Cada hoja muestra arriba
 * los filtros con que se generó.
 */
class ReporteCampanasExport implements WithMultipleSheets
{
    /**
     * @param  list<array{etiqueta: string, valor: string}>  $filtros
     * @param  list<string>  $columnasCampanas
     * @param  list<list<string|int|float|null>>  $campanas
     * @param  list<string>  $columnasCandidatos
     * @param  list<list<string|int|float|null>>|null  $candidatos  null = sin permiso para ver candidatos.
     */
    public function __construct(
        private readonly string $titulo,
        private readonly array $filtros,
        private readonly array $columnasCampanas,
        private readonly array $campanas,
        private readonly array $columnasCandidatos = [],
        private readonly ?array $candidatos = null,
    ) {}

    /**
     * @return array<int, FromArray>
     */
    public function sheets(): array
    {
        $hojas = [new TablaConFiltrosSheet($this->titulo, $this->filtros, $this->columnasCampanas, $this->campanas, 'Campañas')];

        if ($this->candidatos !== null) {
            $hojas[] = new TablaConFiltrosSheet(sprintf('%s · candidatos', $this->titulo), $this->filtros, $this->columnasCandidatos, $this->candidatos, 'Candidatos');
        }

        return $hojas;
    }
}
