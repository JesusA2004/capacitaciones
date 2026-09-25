<?php

namespace App\Services\Formatos\Variables;

/**
 * Números a letras en español de México (montos de pagarés/contratos,
 * fechas en letra). Determinista, sin dependencias.
 */
class NumeroALetras
{
    private const UNIDADES = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];

    private const DECENAS = ['', '', '', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];

    private const CENTENAS = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    public function entero(int $numero): string
    {
        if ($numero === 0) {
            return 'cero';
        }

        if ($numero < 0) {
            return 'menos '.$this->entero(-$numero);
        }

        $partes = [];
        $millones = intdiv($numero, 1_000_000);
        $miles = intdiv($numero % 1_000_000, 1000);
        $resto = $numero % 1000;

        if ($millones > 0) {
            $partes[] = $millones === 1 ? 'un millón' : $this->apocopar($this->entero($millones)).' millones';
        }

        if ($miles > 0) {
            $partes[] = $miles === 1 ? 'mil' : $this->apocopar($this->centenas($miles)).' mil';
        }

        if ($resto > 0) {
            $partes[] = $this->centenas($resto);
        }

        return implode(' ', $partes);
    }

    /**
     * "MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N."
     */
    public function moneda(float $monto): string
    {
        $monto = round($monto, 2);
        $pesos = (int) floor($monto);
        $centavos = (int) round(($monto - $pesos) * 100);

        if ($centavos === 100) {
            $pesos++;
            $centavos = 0;
        }

        $texto = $pesos === 1 ? 'un peso' : $this->apocopar($this->entero($pesos)).($pesos % 1_000_000 === 0 && $pesos > 0 ? ' de pesos' : ' pesos');

        return mb_strtoupper(sprintf('%s %02d/100 M.N.', $texto, $centavos));
    }

    private function centenas(int $numero): string
    {
        if ($numero === 100) {
            return 'cien';
        }

        $centena = intdiv($numero, 100);
        $decena = $numero % 100;
        $texto = self::CENTENAS[$centena];

        if ($decena === 0) {
            return $texto;
        }

        return trim($texto.' '.$this->decenas($decena));
    }

    private function decenas(int $numero): string
    {
        if ($numero < 30) {
            return self::UNIDADES[$numero];
        }

        $unidad = $numero % 10;

        return self::DECENAS[intdiv($numero, 10)].($unidad > 0 ? ' y '.self::UNIDADES[$unidad] : '');
    }

    /**
     * "uno" → "un" / "veintiuno" → "veintiún" antes de un sustantivo
     * (mil, millones, pesos).
     */
    private function apocopar(string $texto): string
    {
        if (str_ends_with($texto, 'veintiuno')) {
            return substr($texto, 0, -strlen('veintiuno')).'veintiún';
        }

        if (str_ends_with($texto, 'uno')) {
            return substr($texto, 0, -3).'un';
        }

        return $texto;
    }
}
