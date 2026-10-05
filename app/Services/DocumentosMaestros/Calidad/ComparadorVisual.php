<?php

namespace App\Services\DocumentosMaestros\Calidad;

use GdImage;
use RuntimeException;

/**
 * Compara dos páginas rasterizadas (ORIGINAL vs GENERADO) por bloques de
 * luminancia. Detecta lo que importa en un formato oficial: logo o imagen
 * perdida, fondo perdido, tabla o texto desplazado, reflow. Las zonas de
 * los campos dinámicos se excluyen con máscaras (rectángulos en puntos PDF).
 *
 *  - Cada página se reduce a una rejilla de bloques (promedio de GD).
 *  - Un bloque "tiene tinta" si su luminancia promedio es menor al umbral.
 *  - Similitud = 1 − bloques distintos / bloques con tinta en cualquiera.
 *  - Bandas: encabezado (12 % superior) y pie (10 % inferior) por separado:
 *    ahí viven logo, membrete y pie de página, que nunca deben moverse.
 *
 * Determinista y sin dependencias más allá de GD.
 */
class ComparadorVisual
{
    private const BLOQUE_PX = 4;

    private const UMBRAL_TINTA = 235;

    private const UMBRAL_DIFERENCIA = 28;

    /**
     * @param  list<array{x: float, y: float, ancho: float, alto: float}>  $mascarasPt  Zonas a ignorar (campos dinámicos), en puntos desde la esquina superior izquierda.
     * @return array{similitud: float, encabezado: float, pie: float, bloques_tinta: int, bloques_distintos: int, mismo_tamano: bool, zonas: list<array{x: float, y: float, ancho: float, alto: float}>}
     */
    public function comparar(string $pngA, string $pngB, float $dpi, array $mascarasPt = []): array
    {
        $a = $this->cargar($pngA);
        $b = $this->cargar($pngB);
        $anchoA = imagesx($a);
        $altoA = imagesy($a);
        $mismoTamano = abs($anchoA - imagesx($b)) <= 2 && abs($altoA - imagesy($b)) <= 2;

        if (! $mismoTamano) {
            return ['similitud' => 0.0, 'encabezado' => 0.0, 'pie' => 0.0, 'bloques_tinta' => 0, 'bloques_distintos' => 0, 'mismo_tamano' => false, 'zonas' => []];
        }

        $columnas = max(1, intdiv($anchoA, self::BLOQUE_PX));
        $filas = max(1, intdiv($altoA, self::BLOQUE_PX));
        $rejillaA = $this->rejilla($a, $columnas, $filas);
        $rejillaB = $this->rejilla($b, $columnas, $filas);
        $ptPorBloque = self::BLOQUE_PX * 72 / $dpi;
        $mascara = $this->mascara($mascarasPt, $ptPorBloque, $columnas, $filas);
        $limiteEncabezado = (int) floor($filas * 0.12);
        $limitePie = (int) ceil($filas * 0.90);
        $cuenta = ['todo' => [0, 0], 'encabezado' => [0, 0], 'pie' => [0, 0]];
        $distintos = [];

        for ($f = 0; $f < $filas; $f++) {
            for ($c = 0; $c < $columnas; $c++) {
                if (isset($mascara[$f][$c])) {
                    continue;
                }

                $la = $rejillaA[$f][$c];
                $lb = $rejillaB[$f][$c];

                if ($la >= self::UMBRAL_TINTA && $lb >= self::UMBRAL_TINTA) {
                    continue;
                }

                $distinto = abs($la - $lb) > self::UMBRAL_DIFERENCIA;
                $banda = $f < $limiteEncabezado ? 'encabezado' : ($f >= $limitePie ? 'pie' : null);

                $cuenta['todo'][0]++;
                $cuenta['todo'][1] += $distinto ? 1 : 0;

                if ($banda !== null) {
                    $cuenta[$banda][0]++;
                    $cuenta[$banda][1] += $distinto ? 1 : 0;
                }

                if ($distinto) {
                    $distintos[] = [$f, $c];
                }
            }
        }

        $similitud = fn (array $par): float => $par[0] === 0 ? 1.0 : round(1 - $par[1] / $par[0], 4);

        return [
            'similitud' => $similitud($cuenta['todo']),
            'encabezado' => $similitud($cuenta['encabezado']),
            'pie' => $similitud($cuenta['pie']),
            'bloques_tinta' => $cuenta['todo'][0],
            'bloques_distintos' => $cuenta['todo'][1],
            'mismo_tamano' => true,
            'zonas' => $this->zonas($distintos, $ptPorBloque),
        ];
    }

    private function cargar(string $png): GdImage
    {
        $imagen = @imagecreatefromstring($png);

        if (! $imagen instanceof GdImage) {
            throw new RuntimeException('Imagen de página inválida.');
        }

        return $imagen;
    }

    /**
     * Luminancia promedio (0 negro – 255 blanco) de cada bloque.
     *
     * @param  int<1, max>  $columnas
     * @param  int<1, max>  $filas
     * @return array<int, array<int, int>>
     */
    private function rejilla(GdImage $imagen, int $columnas, int $filas): array
    {
        $reducida = imagecreatetruecolor($columnas, $filas);

        if (! $reducida instanceof GdImage) {
            throw new RuntimeException('No se pudo reducir la imagen.');
        }

        imagecopyresampled($reducida, $imagen, 0, 0, 0, 0, $columnas, $filas, $columnas * self::BLOQUE_PX, $filas * self::BLOQUE_PX);
        $rejilla = [];

        for ($f = 0; $f < $filas; $f++) {
            for ($c = 0; $c < $columnas; $c++) {
                $rgb = imagecolorat($reducida, $c, $f);
                $rejilla[$f][$c] = (int) round(0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF));
            }
        }

        return $rejilla;
    }

    /**
     * @param  list<array{x: float, y: float, ancho: float, alto: float}>  $mascarasPt
     * @return array<int, array<int, true>>
     */
    private function mascara(array $mascarasPt, float $ptPorBloque, int $columnas, int $filas): array
    {
        $mascara = [];

        foreach ($mascarasPt as $m) {
            // Un bloque de margen alrededor: el texto puede tocar el borde de su caja.
            $c0 = max(0, (int) floor($m['x'] / $ptPorBloque) - 1);
            $c1 = min($columnas - 1, (int) ceil(($m['x'] + $m['ancho']) / $ptPorBloque) + 1);
            $f0 = max(0, (int) floor($m['y'] / $ptPorBloque) - 1);
            $f1 = min($filas - 1, (int) ceil(($m['y'] + $m['alto']) / $ptPorBloque) + 1);

            for ($f = $f0; $f <= $f1; $f++) {
                for ($c = $c0; $c <= $c1; $c++) {
                    $mascara[$f][$c] = true;
                }
            }
        }

        return $mascara;
    }

    /**
     * Hasta 5 zonas (en puntos) donde se concentran las diferencias, para
     * que RH vea DÓNDE cambió el documento.
     *
     * @param  list<array{0: int, 1: int}>  $distintos
     * @return list<array{x: float, y: float, ancho: float, alto: float}>
     */
    private function zonas(array $distintos, float $ptPorBloque): array
    {
        if ($distintos === []) {
            return [];
        }

        // Agrupación por franjas horizontales de 20 bloques.
        $franjas = [];

        foreach ($distintos as [$f, $c]) {
            $clave = intdiv($f, 20);
            $franjas[$clave] ??= ['f0' => $f, 'f1' => $f, 'c0' => $c, 'c1' => $c, 'n' => 0];
            $franjas[$clave]['f0'] = min($franjas[$clave]['f0'], $f);
            $franjas[$clave]['f1'] = max($franjas[$clave]['f1'], $f);
            $franjas[$clave]['c0'] = min($franjas[$clave]['c0'], $c);
            $franjas[$clave]['c1'] = max($franjas[$clave]['c1'], $c);
            $franjas[$clave]['n']++;
        }

        usort($franjas, fn (array $a, array $b): int => $b['n'] <=> $a['n']);

        return array_map(fn (array $z): array => [
            'x' => round($z['c0'] * $ptPorBloque, 1),
            'y' => round($z['f0'] * $ptPorBloque, 1),
            'ancho' => round(($z['c1'] - $z['c0'] + 1) * $ptPorBloque, 1),
            'alto' => round(($z['f1'] - $z['f0'] + 1) * $ptPorBloque, 1),
        ], array_slice($franjas, 0, 5));
    }
}
