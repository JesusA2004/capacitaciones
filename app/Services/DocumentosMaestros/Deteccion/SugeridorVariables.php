<?php

namespace App\Services\DocumentosMaestros\Deteccion;

use App\Enums\ZonaCampoDocumento;
use App\Services\DocumentosMaestros\Variables\CatalogoVariablesDocumento;

/**
 * Sugiere qué dato va en un espacio a partir del texto que lo rodea
 * («CURP: ____» → CURP). Heurísticas LOCALES (sinónimos del catálogo, sin
 * IA externa ni costo por documento). Sugerir no es decidir: el espacio
 * queda AMARILLO hasta que RH lo confirma.
 *
 *  - «Contacto de emergencia» nunca elige solo el nombre: ofrece las cinco
 *    opciones explícitas (completo, nombre, parentesco, teléfono, dirección).
 *  - «Firma», «Huella», «Testigo» marcan el espacio como zona SIN dato.
 *
 * @phpstan-type Sugerencia array{clave: string, etiqueta: string, confianza: float}
 */
class SugeridorVariables
{
    private const EMERGENCIA = [
        'contacto_emergencia_completo' => 0.6,
        'contacto_emergencia_nombre' => 0.5,
        'contacto_emergencia_parentesco' => 0.4,
        'contacto_emergencia_telefono' => 0.4,
        'contacto_emergencia_direccion' => 0.4,
    ];

    public function __construct(private readonly CatalogoVariablesDocumento $catalogo) {}

    /**
     * Zona que sugiere el texto junto al espacio (firma/huella/testigo) o
     * null si parece un dato.
     */
    public function zona(string $contexto): ?ZonaCampoDocumento
    {
        $texto = $this->normalizar($this->cola($contexto, 40));

        return match (true) {
            preg_match('/\bhuella\b/u', $texto) === 1 => ZonaCampoDocumento::Huella,
            preg_match('/\btestigo/u', $texto) === 1 && preg_match('/\bnombre\b/u', $texto) !== 1 => ZonaCampoDocumento::Testigo,
            preg_match('/\bfirma\b|\bfirmas\b/u', $texto) === 1 && preg_match('/\bnombre\b/u', $texto) !== 1 => ZonaCampoDocumento::Firma,
            default => null,
        };
    }

    /**
     * Hasta $maximo sugerencias ordenadas por confianza (0–1).
     *
     * @return list<Sugerencia>
     */
    public function sugerir(string $contexto, int $maximo = 3): array
    {
        // Solo cuenta lo más cercano al espacio (el final del texto previo).
        $cercano = $this->normalizar($this->cola($contexto, 60));

        if ($cercano === '') {
            return [];
        }

        if (str_contains($cercano, 'emergencia')) {
            return $this->lista(self::EMERGENCIA);
        }

        $puntajes = [];

        foreach ($this->catalogo->variables() as $variable) {
            foreach ([$variable['etiqueta'], ...$variable['sinonimos']] as $sinonimo) {
                $normal = $this->normalizar($sinonimo);

                if (mb_strlen($normal) < 2) {
                    continue;
                }

                $posicion = mb_strrpos($cercano, $normal);

                if ($posicion === false || ! $this->palabraCompleta($cercano, $normal, $posicion)) {
                    continue;
                }

                // Más larga y más cerca del espacio = más confiable.
                $distancia = mb_strlen($cercano) - ($posicion + mb_strlen($normal));
                $confianza = min(0.95, 0.35 + min(0.4, mb_strlen($normal) / 40) + max(0.0, 0.2 - $distancia / 100));
                $puntajes[$variable['clave']] = max($puntajes[$variable['clave']] ?? 0.0, round($confianza, 2));
            }
        }

        arsort($puntajes);

        return array_slice($this->lista($puntajes), 0, $maximo);
    }

    /**
     * @param  array<string, float>  $puntajes
     * @return list<Sugerencia>
     */
    private function lista(array $puntajes): array
    {
        $resultado = [];

        foreach ($puntajes as $clave => $confianza) {
            $resultado[] = ['clave' => (string) $clave, 'etiqueta' => $this->catalogo->etiqueta((string) $clave), 'confianza' => $confianza];
        }

        return $resultado;
    }

    private function cola(string $texto, int $caracteres): string
    {
        $limpio = trim((string) preg_replace('/[_\s]+$/u', '', $texto));

        return mb_substr($limpio, max(0, mb_strlen($limpio) - $caracteres));
    }

    private function palabraCompleta(string $texto, string $buscado, int $posicion): bool
    {
        $antes = $posicion === 0 ? ' ' : mb_substr($texto, $posicion - 1, 1);
        $despues = mb_substr($texto, $posicion + mb_strlen($buscado), 1);

        return preg_match('/[\p{L}\p{N}]/u', $antes) !== 1 && ($despues === '' || preg_match('/[\p{L}\p{N}]/u', $despues) !== 1);
    }

    private function normalizar(string $texto): string
    {
        $sinAcentos = strtr(mb_strtolower($texto, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $sinAcentos)));
    }
}
