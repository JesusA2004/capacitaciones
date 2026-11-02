<?php

namespace App\Enums;

/**
 * Clasificación ANALÍTICA de una baja para los indicadores de rotación
 * (voluntaria / involuntaria / sin clasificar). La fija la causal homologada
 * (App\Models\CausalAnalitica) y es distinta de la forma de salida
 * administrativa o jurídica (App\Enums\FormaSalida): p. ej. «Abandono de
 * empleo o faltas» se cuenta como voluntaria en la estadística histórica,
 * aunque en el flujo de Bajas sea una rescisión. Ninguna sustituye a la otra.
 *
 * Total de bajas = voluntarias + involuntarias + sin clasificar.
 */
enum ClasificacionSalida: string
{
    case Voluntaria = 'voluntaria';
    case Involuntaria = 'involuntaria';
    case SinClasificar = 'sin_clasificar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Voluntaria => 'Voluntaria',
            self::Involuntaria => 'Involuntaria',
            self::SinClasificar => 'Sin clasificar',
        };
    }
}
