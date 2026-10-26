<?php

namespace App\Services\Expedientes;

use App\Services\Expedientes\MigracionInicial\Normalizador;

/**
 * Comparación TOLERANTE de nombres para buscar a mano carpetas del NAS
 * («Juan Pérez» en el sistema, «Juam Peres» en Synology). Solo compara:
 * nunca se guarda ni se renombra nada con esto.
 *
 * Tokens = Normalizador::tokensNombre() (mayúsculas ASCII, sin acentos,
 * fechas, números ni palabras vacías). Entre dos tokens:
 *   1.00  iguales
 *   0.92  suenan igual en español (fonetica(): Z/S, V/B, H muda, LL/Y,
 *         C/K/QU, GE/JE, M final/N, letras dobles…)
 *   0.90  el tecleado es el inicio del otro (≥ 2 letras: filtrar mientras se escribe)
 *   ≤0.85 parecido por distancia de edición (Levenshtein), sobre el texto y
 *         sobre la clave fonética, lo que dé más.
 */
final class SimilitudNombres
{
    /** Parecido mínimo de CADA token tecleado para que una carpeta aparezca al buscar. */
    public const UMBRAL_TOKEN = 0.6;

    /**
     * @return list<string>
     */
    public static function tokens(?string $texto): array
    {
        return Normalizador::tokensNombre($texto);
    }

    /** Clave fonética (español de México) de un token ya normalizado (A-Z). */
    public static function fonetica(string $token): string
    {
        $reglas = [
            '/CH/' => '#',
            '/H/' => '',
            '/#/' => 'CH',
            '/LL/' => 'Y',
            '/QU/' => 'K',
            '/GU([EI])/' => '&$1',
            '/G([EI])/' => 'J$1',
            '/&/' => 'G',
            '/C([EI])/' => 'S$1',
            '/C/' => 'K',
            '/Z/' => 'S',
            '/V/' => 'B',
            '/W/' => 'U',
            '/Y$/' => 'I',
            '/M$/' => 'N',
            '/(.)\1+/' => '$1',
        ];

        $clave = $token;

        foreach ($reglas as $patron => $reemplazo) {
            $clave = (string) preg_replace($patron, $reemplazo, $clave);
        }

        return $clave;
    }

    /** Parecido 0..1 de un token $a (p. ej. el tecleado) contra $b. */
    public static function token(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $fa = self::fonetica($a);
        $fb = self::fonetica($b);

        if ($fa !== '' && $fa === $fb) {
            return 0.92;
        }

        if (strlen($a) >= 2 && strlen($a) < strlen($b) && str_starts_with($b, $a)) {
            return 0.9;
        }

        $texto = 1 - levenshtein($a, $b) / max(strlen($a), strlen($b), 1);
        $sonido = 1 - levenshtein($fa, $fb) / max(strlen($fa), strlen($fb), 1);

        return round(max(0.0, max($texto, $sonido)) * 0.85, 4);
    }

    /**
     * Promedio del mejor parecido de cada token de $a dentro de $b.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function cobertura(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $suma = 0.0;

        foreach ($a as $ta) {
            $suma += self::mejor($ta, $b);
        }

        return $suma / count($a);
    }

    /**
     * Búsqueda mientras se escribe: TODOS los tokens tecleados deben
     * parecerse (≥ UMBRAL_TOKEN) a alguno del candidato. null = no aparece.
     *
     * @param  list<string>  $consulta
     * @param  list<string>  $candidato
     */
    public static function busqueda(array $consulta, array $candidato): ?float
    {
        if ($consulta === [] || $candidato === []) {
            return null;
        }

        $suma = 0.0;

        foreach ($consulta as $tc) {
            $mejor = self::mejor($tc, $candidato);

            if ($mejor < self::UMBRAL_TOKEN) {
                return null;
            }

            $suma += $mejor;
        }

        return round($suma / count($consulta), 4);
    }

    /**
     * Qué tanto una carpeta parece ser de una persona (0..1). Pesa más que
     * cada palabra de la carpeta esté en la persona (las carpetas suelen
     * traer menos apellidos) que al revés. El match exacto/alto de la
     * migración (Normalizador::similitud) se respeta si es mayor.
     *
     * @param  list<string>  $persona
     * @param  list<string>  $carpeta
     */
    public static function persona(array $persona, array $carpeta): float
    {
        if ($persona === [] || $carpeta === []) {
            return 0.0;
        }

        $tolerante = 0.6 * self::cobertura($carpeta, $persona) + 0.4 * self::cobertura($persona, $carpeta);

        return round(max($tolerante, Normalizador::similitud($persona, $carpeta)), 4);
    }

    /**
     * @param  list<string>  $candidatos
     */
    private static function mejor(string $token, array $candidatos): float
    {
        $mejor = 0.0;

        foreach ($candidatos as $c) {
            $mejor = max($mejor, self::token($token, $c));

            if ($mejor >= 1.0) {
                break;
            }
        }

        return $mejor;
    }
}
