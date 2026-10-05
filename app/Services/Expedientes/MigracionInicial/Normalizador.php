<?php

namespace App\Services\Expedientes\MigracionInicial;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use Throwable;

/**
 * Normalización para COMPARAR (nunca para guardar: el valor original
 * importante se conserva). Mayúsculas, sin acentos, espacios colapsados,
 * sin fechas ni «(1)» en nombres de carpeta/archivo.
 */
final class Normalizador
{
    /** Palabras que no aportan a la identidad de un nombre. */
    private const VACIAS = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y', 'EXPEDIENTE', 'EXP', 'BAJA', 'ALTA', 'PDF', 'SR', 'SRA', 'LIC'];

    public static function texto(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $valor)));

        return $valor === '' || in_array(Str::upper($valor), ['N/A', 'NA', 'NULL', '-', '--', 'SIN DATO', 'S/D'], true) ? null : $valor;
    }

    /** Clave de comparación: MAYÚSCULAS ASCII, sin signos, espacios simples. */
    public static function clave(?string $valor): string
    {
        $valor = Str::upper(Str::ascii((string) $valor));
        $valor = (string) preg_replace('/[^A-Z0-9 ]+/', ' ', $valor);

        return trim((string) preg_replace('/\s+/', ' ', $valor));
    }

    /**
     * Tokens de un nombre de persona tal como viene en una carpeta o PDF
     * histórico: quita fechas (19-02-2024, 2024.02.19…), «(1)», números,
     * extensiones y palabras vacías.
     *
     * @return list<string>
     */
    public static function tokensNombre(?string $valor): array
    {
        $valor = (string) $valor;
        $valor = (string) preg_replace('/\.(pdf|docx?|jpe?g|png)$/i', '', $valor);
        $valor = (string) preg_replace('/\b\d{1,4}[-\/._]\d{1,2}[-\/._]\d{1,4}\b/', ' ', $valor);
        $valor = (string) preg_replace('/\(\s*\d+\s*\)/', ' ', $valor);
        $valor = (string) preg_replace('/\d+/', ' ', $valor);
        $tokens = array_values(array_filter(explode(' ', self::clave($valor)), fn (string $t) => $t !== '' && strlen($t) > 1 && ! in_array($t, self::VACIAS, true)));

        return $tokens;
    }

    /** Fecha incluida en el nombre de una carpeta (dd-mm-aaaa), si la hay. */
    public static function fechaEnNombre(string $valor): ?string
    {
        if (preg_match('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})\b/', $valor, $m) !== 1) {
            return null;
        }

        $anio = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

        return checkdate((int) $m[2], (int) $m[1], $anio) ? sprintf('%04d-%02d-%02d', $anio, (int) $m[2], (int) $m[1]) : null;
    }

    public static function curp(?string $valor): ?string
    {
        $valor = Str::upper((string) preg_replace('/\s+/', '', (string) $valor));

        return $valor !== '' ? $valor : null;
    }

    public static function curpValida(?string $curp): bool
    {
        return $curp !== null && preg_match('/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/', $curp) === 1;
    }

    public static function rfc(?string $valor): ?string
    {
        $valor = Str::upper((string) preg_replace('/[\s-]+/', '', (string) $valor));

        return $valor !== '' ? $valor : null;
    }

    /** RFC de persona física con homoclave (13): suficientemente confiable. */
    public static function rfcConfiable(?string $rfc): bool
    {
        return $rfc !== null && preg_match('/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/', $rfc) === 1;
    }

    public static function correo(?string $valor): ?string
    {
        $valor = Str::lower(trim((string) $valor));

        return filter_var($valor, FILTER_VALIDATE_EMAIL) !== false ? $valor : null;
    }

    public static function telefono(?string $valor): ?string
    {
        $valor = self::texto($valor);

        return $valor !== null ? trim((string) preg_replace('/[^0-9+ ]+/', ' ', $valor)) ?: null : null;
    }

    /**
     * Fecha del Excel: número de serie de Excel, d/m/aaaa, aaaa-mm-dd.
     * Lo que no se entiende queda null (nunca se inventa).
     */
    public static function fecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            if (is_numeric($valor) && (float) $valor > 1000) {
                return CarbonImmutable::instance(FechaExcel::excelToDateTimeObject((float) $valor))->toDateString();
            }

            $texto = trim((string) $valor);

            if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/', $texto, $m) === 1) {
                $anio = strlen($m[3]) === 2 ? ((int) $m[3] > 40 ? 1900 : 2000) + (int) $m[3] : (int) $m[3];

                return checkdate((int) $m[2], (int) $m[1], $anio) ? sprintf('%04d-%02d-%02d', $anio, (int) $m[2], (int) $m[1]) : null;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $texto) === 1) {
                return CarbonImmutable::parse(substr($texto, 0, 10))->toDateString();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Similitud 0..1 entre dos nombres (tokens). 1 = mismos tokens; un
     * nombre contenido en el otro con ≥ 3 tokens ≈ 0.9 (p. ej. «ALBERTO
     * CARLOS BUENO» ⊂ «JOSE ALBERTO CARLOS BUENO»); lo demás se calcula por
     * tokens parecidos (una letra de diferencia en palabras largas).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function similitud(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $sa = array_unique($a);
        $sb = array_unique($b);
        sort($sa);
        sort($sb);

        if ($sa === $sb) {
            return 1.0;
        }

        $comunes = count(array_intersect($sa, $sb));
        $menor = min(count($sa), count($sb));
        $mayor = max(count($sa), count($sb));

        if ($comunes === $menor && $menor >= 3) {
            return 0.9;
        }

        // Tokens casi iguales (errores de captura: «GONZALES»/«GONZALEZ»).
        $parecidos = 0;

        foreach ($sa as $ta) {
            foreach ($sb as $tb) {
                if ($ta === $tb || (strlen($ta) >= 5 && strlen($tb) >= 5 && levenshtein($ta, $tb) <= 1)) {
                    $parecidos++;

                    break;
                }
            }
        }

        if ($parecidos === $menor && $menor >= 3 && $mayor === $menor) {
            return 0.88;
        }

        return round(min(0.84, $parecidos / $mayor), 4);
    }
}
