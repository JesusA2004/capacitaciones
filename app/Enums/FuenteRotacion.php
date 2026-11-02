<?php

namespace App\Enums;

/**
 * De dónde sale una cifra de rotación:
 *
 *  - Sistema: baja capturada en el módulo de Bajas (incluye los cierres
 *    anteriores al módulo que la migración convirtió en baja).
 *  - Historico: baja individual de un periodo anterior al sistema, cargada con
 *    una importación auditable (App\Models\BajaHistorica).
 *  - Agregado: total mensual por sucursal del Excel de rotación
 *    (rotacion_periodos), sin detalle de persona, causal ni antigüedad.
 */
enum FuenteRotacion: string
{
    case Sistema = 'sistema';
    case Historico = 'historico';
    case Agregado = 'agregado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sistema => 'Sistema',
            self::Historico => 'Histórico individual',
            self::Agregado => 'Histórico agregado',
        };
    }
}
