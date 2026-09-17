<?php

namespace App\Services\Nomina;

use App\Models\Colaborador;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Recibo de nómina SIMPLE e informativo: percepciones/deducciones básicas a
 * partir del sueldo mensual capturado en el expediente. No es un CFDI
 * timbrado ante el SAT ni calcula ISR/IMSS — para eso se necesita un
 * proveedor de nómina certificado. Sirve para que RH le entregue al
 * colaborador un comprobante interno rápido.
 */
class ReciboNominaService
{
    /**
     * @return array{colaborador: Colaborador, periodo: CarbonInterface, percepciones: array<int, array{concepto: string, monto: float}>, deducciones: array<int, array{concepto: string, monto: float}>, total_percepciones: float, total_deducciones: float, neto: float}
     */
    public function generar(Colaborador $colaborador, ?CarbonInterface $periodo = null): array
    {
        $periodo ??= Carbon::now();
        $sueldoMensual = (float) ($colaborador->sueldo_mensual ?? 0);

        $percepciones = [
            ['concepto' => 'Sueldo mensual', 'monto' => $sueldoMensual],
        ];

        // Sin deducciones automáticas (ISR/IMSS): este recibo es informativo,
        // no un cálculo fiscal. RH puede seguir manejando la nómina real con
        // su proveedor certificado.
        $deducciones = [];

        $totalPercepciones = round(array_sum(array_column($percepciones, 'monto')), 2);
        $totalDeducciones = round(array_sum(array_column($deducciones, 'monto')), 2);

        return [
            'colaborador' => $colaborador,
            'periodo' => $periodo,
            'percepciones' => $percepciones,
            'deducciones' => $deducciones,
            'total_percepciones' => $totalPercepciones,
            'total_deducciones' => $totalDeducciones,
            'neto' => round($totalPercepciones - $totalDeducciones, 2),
        ];
    }
}
