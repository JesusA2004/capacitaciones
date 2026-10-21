<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Regla única para componer «nombre + apellidos» de una persona.
 *
 * Hubo capturas donde el campo «nombre» traía también los apellidos
 * («NANCY NALLELY LOPEZ GARCIA» + apellidos «Lopez Garcia»): el expediente
 * mostraba «NANCY NALLELY LOPEZ GARCIA Lopez Garcia» y el contrato los
 * imprimía dos veces. La comparación ignora mayúsculas, acentos y espacios
 * repetidos, y solo cuenta si los apellidos (dos o más palabras) están AL
 * FINAL del nombre como palabras completas (y queda al menos una palabra de
 * nombre).
 */
final class NombrePersona
{
    /**
     * Texto comparable: sin acentos, en minúsculas y con un solo espacio.
     */
    public static function normalizar(?string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower(Str::ascii((string) $texto))));
    }

    /**
     * true si el nombre termina con los apellidos completos.
     *
     * Solo con dos o más palabras de apellidos: con uno solo hay nombres
     * reales que coinciden («José Cruz» + «Cruz» = José Cruz Cruz) y no se
     * tocan; que los nombres terminen con AMBOS apellidos no ocurre en la
     * práctica, es la captura duplicada.
     */
    public static function terminaConApellidos(?string $nombre, ?string $apellidos): bool
    {
        $n = self::normalizar($nombre);
        $a = self::normalizar($apellidos);

        return str_contains($a, ' ') && $n !== $a && str_ends_with(sprintf(' %s', $n), sprintf(' %s', $a));
    }

    /**
     * El nombre sin los apellidos repetidos al final (respeta la escritura
     * original de las palabras que se conservan).
     */
    public static function sinApellidos(?string $nombre, ?string $apellidos): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));

        if (! self::terminaConApellidos($limpio, $apellidos)) {
            return $limpio;
        }

        $palabras = explode(' ', $limpio);
        $cuantas = count(explode(' ', self::normalizar($apellidos)));

        return implode(' ', array_slice($palabras, 0, count($palabras) - $cuantas));
    }

    /**
     * «Nombre Apellidos» sin duplicar los apellidos si el nombre ya los trae.
     */
    public static function completo(?string $nombre, ?string $apellidos): string
    {
        return trim(sprintf('%s %s', self::sinApellidos($nombre, $apellidos), trim((string) $apellidos)));
    }
}
