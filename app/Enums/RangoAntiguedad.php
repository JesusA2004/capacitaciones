<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Rangos de antigüedad al salir, sin traslapes, en meses CALENDARIO desde la
 * fecha de ingreso de ESA relación laboral hasta el último día laborado:
 * [0, 3) · [3, 6) · [6, 12) · [12, 36) · [36, ∞). Sin fecha de ingreso (o con
 * una baja anterior al ingreso) el registro queda «Sin fecha de ingreso» y se
 * excluye de los denominadores.
 */
enum RangoAntiguedad: string
{
    case Menos3Meses = 'menos_3_meses';
    case De3A6Meses = 'de_3_a_6_meses';
    case De6A12Meses = 'de_6_a_12_meses';
    case De1A3Anios = 'de_1_a_3_anios';
    case Mas3Anios = 'mas_3_anios';
    case SinFecha = 'sin_fecha';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Menos3Meses => 'Menos de 3 meses',
            self::De3A6Meses => 'De 3 a menos de 6 meses',
            self::De6A12Meses => 'De 6 a menos de 12 meses',
            self::De1A3Anios => 'De 1 a menos de 3 años',
            self::Mas3Anios => '3 años o más',
            self::SinFecha => 'Sin fecha de ingreso válida',
        };
    }

    /**
     * Rango de una baja. La fecha de baja anterior al ingreso no es un rango:
     * es un dato que hay que revisar (FechasInconsistentes en los hallazgos).
     */
    public static function de(?CarbonInterface $ingreso, ?CarbonInterface $baja): self
    {
        if ($ingreso === null || $baja === null || $baja->lt($ingreso)) {
            return self::SinFecha;
        }

        $meses = self::mesesCompletos($ingreso, $baja);

        return match (true) {
            $meses < 3 => self::Menos3Meses,
            $meses < 6 => self::De3A6Meses,
            $meses < 12 => self::De6A12Meses,
            $meses < 36 => self::De1A3Anios,
            default => self::Mas3Anios,
        };
    }

    /** Meses calendario completos (31 ene → 28 feb = 0; 15 ene → 15 abr = 3). */
    public static function mesesCompletos(CarbonInterface $ingreso, CarbonInterface $baja): int
    {
        return (int) $ingreso->copy()->startOfDay()->diffInMonths($baja->copy()->startOfDay(), true);
    }

    /** Antes de seis meses (rotación temprana del tablero). */
    public function esTemprana(): bool
    {
        return $this === self::Menos3Meses || $this === self::De3A6Meses;
    }
}
