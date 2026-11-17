<?php

namespace App\Services\Seguimiento;

/**
 * Lectura TIPADA de lo que devuelve la API de Reportes (JSON = mixed). Un
 * campo ausente o de otro tipo nunca se inventa como 0: los montos
 * opcionales se leen con decimalONulo() y se muestran como «—».
 */
final class DatoReportes
{
    /**
     * @return array<string, mixed>
     */
    public static function arreglo(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $resultado = [];

        foreach ($valor as $clave => $dato) {
            $resultado[(string) $clave] = $dato;
        }

        return $resultado;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function lista(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $resultado = [];

        foreach ($valor as $dato) {
            if (is_array($dato)) {
                $resultado[] = self::arreglo($dato);
            }
        }

        return $resultado;
    }

    public static function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_string($valor) && ctype_digit($valor)) {
            return (int) $valor;
        }

        if (is_float($valor) && floor($valor) === $valor) {
            return (int) $valor;
        }

        return null;
    }

    public static function decimalONulo(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return round((float) $valor, 2);
        }

        if (is_string($valor) && is_numeric($valor)) {
            return round((float) $valor, 2);
        }

        return null;
    }

    public static function decimal(mixed $valor): float
    {
        return self::decimalONulo($valor) ?? 0.0;
    }

    public static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : (is_int($valor) || is_float($valor) ? sprintf('%s', $valor) : '');
    }

    public static function textoONulo(mixed $valor): ?string
    {
        $texto = self::texto($valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Lee una ruta con puntos («kpis.placement.amount») de un arreglo.
     */
    public static function ruta(mixed $origen, string $ruta): mixed
    {
        $actual = $origen;

        foreach (explode('.', $ruta) as $parte) {
            if (! is_array($actual) || ! array_key_exists($parte, $actual)) {
                return null;
            }

            $actual = $actual[$parte];
        }

        return $actual;
    }

    /**
     * @return list<int>
     */
    public static function enteros(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $resultado = [];

        foreach ($valor as $dato) {
            $entero = self::entero($dato);

            if ($entero !== null) {
                $resultado[] = $entero;
            }
        }

        return $resultado;
    }

    /**
     * Nombre comparable: sin acentos, mayúsculas, un solo espacio. Solo
     * para SUGERIR vínculos (nunca vincula por sí solo).
     */
    public static function nombreComparable(string $nombre): string
    {
        $sinAcentos = strtr(mb_strtoupper($nombre), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
        $limpio = preg_replace('/[^A-Z0-9 ]+/', ' ', $sinAcentos) ?? $sinAcentos;

        return trim(preg_replace('/\s+/', ' ', $limpio) ?? $limpio);
    }

    /**
     * Mismas palabras sin importar el orden («PEREZ LOPEZ JUAN» = «JUAN PEREZ LOPEZ»).
     */
    public static function firmaNombre(string $nombre): string
    {
        $palabras = array_values(array_filter(explode(' ', self::nombreComparable($nombre)), fn (string $p): bool => $p !== ''));
        sort($palabras);

        return implode(' ', $palabras);
    }
}
