<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\EstadoCorteRecepcion;
use App\Enums\EstadoRecepcionDocumento;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;

/**
 * Estado CALCULADO del corte (todas las sucursales, sin alcance):
 *
 *  - En preparación: todavía no se calcula lo esperado.
 *  - Completo: hay renglones y ninguno está pendiente ni «No recibido».
 *  - Con pendientes: el mes ya terminó (hora de México) y falta algo.
 *  - Abierto: el mes sigue en curso (o aún no tiene renglones).
 *  - Cerrado: solo lo pone/quita RecepcionDocumentalService (cerrar / reabrir);
 *    aquí nunca se toca.
 */
class EstadoCorteService
{
    public function calcular(RecepcionDocumentalCorte $corte): EstadoCorteRecepcion
    {
        if ($corte->estaCerrado()) {
            return EstadoCorteRecepcion::Cerrado;
        }

        if ($corte->materializado_en === null) {
            return EstadoCorteRecepcion::EnPreparacion;
        }

        $total = RecepcionDocumentalRenglon::query()->where('corte_id', $corte->id)->count();
        $abiertos = RecepcionDocumentalRenglon::query()
            ->where('corte_id', $corte->id)
            ->whereIn('estado', [EstadoRecepcionDocumento::Pendiente->value, EstadoRecepcionDocumento::NoRecibido->value])
            ->count();

        if ($total > 0 && $abiertos === 0) {
            return EstadoCorteRecepcion::Completo;
        }

        return $total > 0 && PeriodoRecepcion::delCorte($corte)->haTerminado()
            ? EstadoCorteRecepcion::ConPendientes
            : EstadoCorteRecepcion::Abierto;
    }

    public function recalcular(RecepcionDocumentalCorte $corte): EstadoCorteRecepcion
    {
        $estado = $this->calcular($corte);

        if ($corte->estado !== $estado) {
            $corte->forceFill(['estado' => $estado])->save();
        }

        return $estado;
    }
}
