<?php

namespace App\Exports;

use App\Exports\Sheets\ReporteDetalleSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel de Rotación de personal: las mismas cifras de la pantalla
 * (RotacionExportService::datos) en una hoja por sección, más la relación
 * individual de bajas cuando el usuario puede ver personas.
 */
class RotacionExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $datos
     * @param  list<array<string, mixed>>|null  $detalle
     */
    public function __construct(
        private readonly array $datos,
        private readonly ?array $detalle,
    ) {}

    /**
     * @return array<int, FromArray>
     */
    public function sheets(): array
    {
        $r = $this->datos['resumen'];
        $a = $this->datos['alcance'];
        $hojas = [
            new ReporteDetalleSheet(['Indicador', 'Valor'], [
                ['Periodo', sprintf('%s a %s', $a['desde'], $a['hasta'])],
                ['Empresa', $a['empresa']],
                ['Sucursal', $a['sucursal']],
                ['Fuente', $a['fuente']],
                ['Total de bajas', $r['total']],
                ['Meses considerados', $r['meses']],
                ['Promedio mensual de bajas', $r['promedio_mensual']],
                ['Voluntarias', $r['voluntarias']['bajas']],
                ['Voluntarias %', $r['voluntarias']['porcentaje']],
                ['Involuntarias', $r['involuntarias']['bajas']],
                ['Involuntarias %', $r['involuntarias']['porcentaje']],
                ['Sin clasificar', $r['sin_clasificar']['bajas']],
                ['Sin clasificar %', $r['sin_clasificar']['porcentaje']],
                ['  de ellas sin desglose (histórico agregado)', $r['sin_clasificar']['sin_desglose']],
                ['Rotación temprana (< 6 meses)', $r['temprana']['bajas']],
                ['  sobre bajas con fecha de ingreso válida', $r['temprana']['base']],
                ['Rotación temprana %', $r['temprana']['porcentaje']],
                ['Índice de rotación del periodo %', $r['rotacion']['indice'] ?? 'Sin datos de plantilla'],
                ['Plantilla promedio', $r['rotacion']['plantilla_promedio'] ?? 'Sin datos de plantilla'],
                ['Promedio de índices mensuales %', $r['rotacion']['promedio_indices'] ?? 'Sin datos de plantilla'],
                ['Generado', $a['generado']],
            ], 'Resumen'),
            new ReporteDetalleSheet(['Mes', 'Bajas', 'Voluntarias', 'Involuntarias', 'Sin clasificar', 'Sin desglose', 'Plantilla promedio', 'Índice %', 'Nota'], array_map(fn (array $m): array => [
                $m['periodo'], $m['sin_datos'] ? null : $m['total'], $m['voluntarias'], $m['involuntarias'], $m['sin_clasificar'], $m['sin_desglose'], $m['plantilla_promedio'], $m['indice'],
                implode(' · ', array_filter([$m['sin_datos'] ? 'Sin datos' : null, $m['corte_parcial'] ? 'Corte parcial' : null, $m['discrepancias'] > 0 ? 'Descuadre con histórico agregado' : null])),
            ], $this->datos['tendencia']['meses']), 'Mensual'),
            new ReporteDetalleSheet(['Causal homologada', 'Clasificación', 'Bajas', '% de bajas con causal'], array_map(fn (array $c): array => [$c['nombre'], $c['clasificacion_etiqueta'], $c['bajas'], $c['porcentaje']], $this->datos['causales']['causales']), 'Causales'),
            new ReporteDetalleSheet(['Sucursal', 'Empresa', 'Bajas', 'Participación %', 'Plantilla promedio', 'Índice %', 'Voluntarias', 'Involuntarias', 'Sin clasificar', 'Causal principal', 'Temprana %'], array_map(fn (array $s): array => [
                $s['sucursal'], $s['empresa'], $s['bajas'], $s['participacion'], $s['plantilla_promedio'], $s['indice'], $s['voluntarias'], $s['involuntarias'], $s['sin_clasificar'], $s['causal_principal'], $s['temprana'],
            ], $this->datos['sucursales']), 'Sucursales'),
            new ReporteDetalleSheet(['Antigüedad', 'Bajas', '% (con fecha válida)'], array_map(fn (array $r): array => [$r['etiqueta'], $r['bajas'], $r['porcentaje']], $this->datos['antiguedad']['rangos']), 'Antigüedad'),
            new ReporteDetalleSheet(['Sucursal', ...array_map(fn (array $c): string => $c['nombre'], $this->datos['matriz']['columnas']), 'Total'], array_map(fn (array $f): array => [
                $f['sucursal'], ...array_values($f['celdas']), $f['total'],
            ], $this->datos['matriz']['filas']), 'Matriz'),
        ];

        if ($this->detalle !== null) {
            $hojas[] = new ReporteDetalleSheet(
                ['Último día laborado', 'Nombre', 'No. empleado', 'Empresa', 'Sucursal', 'Puesto', 'Área', 'Fecha de ingreso', 'Antigüedad (días)', 'Motivo original', 'Causal del catálogo', 'Causal homologada', 'Clasificación', 'Estado', 'Origen'],
                array_map(fn (array $d): array => [
                    $d['fecha_baja'], $d['nombre'], $d['numero_empleado'], $d['empresa'], $d['sucursal'], $d['puesto'], $d['departamento'], $d['fecha_ingreso'], $d['antiguedad_dias'],
                    $d['motivo_original'], $d['causal_catalogo'], $d['causal'], $d['clasificacion_etiqueta'], $d['estado'], $d['fuente_etiqueta'],
                ], $this->detalle),
                'Bajas',
            );
        }

        return $hojas;
    }
}
