<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel del KPI de rotación de personal del dashboard (ver
 * App\Services\Reportes\MetricasRhDashboardService::rotacion()) — mismos
 * datos que ve la pantalla, respetando los filtros aplicados (sucursal,
 * rango de fechas).
 */
class RotacionPersonalExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array<string, mixed>  $datos  Resultado de MetricasRhDashboardService::rotacion().
     */
    public function __construct(private readonly array $datos) {}

    public function title(): string
    {
        return 'Rotación de personal';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Indicador', 'Valor'];
    }

    /**
     * @return array<int, array<int, string|int|float>>
     */
    public function array(): array
    {
        $filas = [
            ['Periodo', "{$this->datos['periodo']['desde']} — {$this->datos['periodo']['hasta']}"],
            ['Plantilla actual', $this->datos['plantilla_actual']],
            ['Altas del periodo', $this->datos['altas']],
            ['Bajas del periodo', $this->datos['bajas']],
            ['% Rotación', $this->datos['rotacion_porcentaje']],
            ['Plantilla autorizada (headcount)', $this->datos['eficiencia']['plantilla_autorizada']],
            ['Vacantes disponibles (headcount)', $this->datos['eficiencia']['vacantes']],
            ['% Cumplimiento headcount', $this->datos['eficiencia']['cumplimiento']],
            ['', ''],
            ['Género', 'Colaboradores activos'],
        ];

        foreach ($this->datos['genero'] as $fila) {
            $filas[] = [$fila['etiqueta'], $fila['valor']];
        }

        $filas[] = ['', ''];
        $filas[] = ['Altas por sucursal', 'Total'];

        foreach ($this->datos['altasPorSucursal'] as $fila) {
            $filas[] = [$fila['etiqueta'], $fila['valor']];
        }

        $filas[] = ['', ''];
        $filas[] = ['Bajas por sucursal', 'Total'];

        foreach ($this->datos['bajasPorSucursal'] as $fila) {
            $filas[] = [$fila['etiqueta'], $fila['valor']];
        }

        return $filas;
    }
}
