<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoReciboNomina;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\ReciboNomina;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;

/**
 * NÓMINA: un «Recibo de nómina firmado» por cada recibo EMITIDO real cuya
 * fecha de pago (DATE, obligatoria en el recibo) cae en el mes
 * (configurable a periodo_fin en recepcion_documental.nomina.columna_fecha).
 * Los borradores no cuentan y un recibo cancelado deja de esperarse. Nunca
 * se suponen «4 semanas» ni 2 quincenas: si en el mes se emitieron 3
 * recibos a una persona, se esperan 3.
 */
class ReglaRecibosNomina implements ReglaRecepcion
{
    public const TIPO = 'recibo_nomina';

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::RecibosNomina;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        $columna = config('recepcion_documental.nomina.columna_fecha') === 'periodo_fin' ? 'periodo_fin' : 'fecha_pago';

        $recibos = ReciboNomina::query()
            ->where('estado', EstadoReciboNomina::Emitido->value)
            ->whereBetween($columna, $periodo->rangoFechas())
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])])
            ->orderBy('id')
            ->get();

        $esperados = [];

        foreach ($recibos as $recibo) {
            $fecha = $columna === 'periodo_fin' ? $recibo->periodo_fin : $recibo->fecha_pago;

            $esperados[] = EsperadoRecepcion::deColaborador(
                $this->regla(),
                self::TIPO,
                EsperadoRecepcion::etiquetaTipo(self::TIPO),
                $recibo,
                $recibo->colaborador,
                $fecha->toDateString(),
                sprintf(
                    'Recibo %s · periodo %s – %s · pago %s',
                    $recibo->folio ?? sprintf('#%d', $recibo->id),
                    $recibo->periodo_inicio->format('d/m/Y'),
                    $recibo->periodo_fin->format('d/m/Y'),
                    $recibo->fecha_pago->format('d/m/Y'),
                ),
            );
        }

        return $esperados;
    }
}
