<?php

namespace App\Enums;

/**
 * Cómo se convierten los días naturales no pagables de un periodo en días
 * salariales pagados (config/nomina_incidencias.php). La base salarial (15 o 7)
 * no siempre coincide con los días del calendario (16 en una segunda quincena
 * de mes de 31), por eso la regla es explícita y la valida RH.
 */
enum ReglaDiasPagados: string
{
    /** Días naturales pagables, con tope en la base: 16 − 11 = 5. Misma regla que el ingreso a media quincena. */
    case DiasCalendarioConTope = 'dias_calendario_con_tope';
    /** Base menos días no pagables, con piso en cero: 15 − 11 = 4. */
    case RestaDirecta = 'resta_directa';
    /** Base proporcional a los días del calendario: 15 × 5 ÷ 16 = 4.69. */
    case Proporcional = 'proporcional';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DiasCalendarioConTope => 'Días naturales pagables, con tope en la base salarial',
            self::RestaDirecta => 'Base salarial menos días no pagables',
            self::Proporcional => 'Base salarial proporcional a los días del calendario',
        };
    }

    /**
     * Días salariales a pagar. Nunca negativos ni mayores a la base.
     */
    public function diasPagados(int $base, int $naturales, int $noPagables): float
    {
        if ($noPagables <= 0) {
            return (float) $base;
        }

        $pagables = max(0, $naturales - $noPagables);

        return match ($this) {
            self::DiasCalendarioConTope => (float) min($base, $pagables),
            self::RestaDirecta => (float) max(0, $base - $noPagables),
            self::Proporcional => $naturales > 0 ? round($base * $pagables / $naturales, 2) : 0.0,
        };
    }
}
