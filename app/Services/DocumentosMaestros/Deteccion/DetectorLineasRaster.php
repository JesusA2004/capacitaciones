<?php

namespace App\Services\DocumentosMaestros\Deteccion;

/**
 * Líneas horizontales dibujadas en una página rasterizada (GD): las rayas
 * donde una persona escribiría a mano («Nombre: ________» hecho con una
 * línea vectorial o impresa en un escaneo). Solo líneas delgadas y largas;
 * los rectángulos rellenos y los bordes gruesos se descartan.
 *
 * Determinista y local: sin OCR ni IA.
 *
 * @phpstan-type Linea array{x: float, y: float, ancho: float}
 */
class DetectorLineasRaster
{
    /** Luminancia (0–255) por debajo de la cual un píxel es «tinta» (alta: las líneas finas salen grises por el antialias). */
    private const TINTA = 200;

    /** Largo mínimo de una línea, en mm. */
    private const LARGO_MINIMO_MM = 14.0;

    /** Grosor máximo de una línea, en mm. */
    private const GROSOR_MAXIMO_MM = 1.0;

    /**
     * @return list<Linea> en mm, origen arriba-izquierda (y = la línea).
     */
    public function detectar(string $png, float $dpi): array
    {
        $imagen = @imagecreatefromstring($png);

        if ($imagen === false) {
            return [];
        }

        $mmPorPx = 25.4 / max(1.0, $dpi);
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $largoMinimo = (int) ceil(self::LARGO_MINIMO_MM / $mmPorPx);
        $grosorMaximo = max(1, (int) ceil(self::GROSOR_MAXIMO_MM / $mmPorPx));
        $tramos = [];

        for ($y = 0; $y < $alto; $y++) {
            $inicio = null;

            for ($x = 0; $x <= $ancho; $x++) {
                $tinta = $x < $ancho && $this->esTinta($imagen, $x, $y);

                if ($tinta && $inicio === null) {
                    $inicio = $x;
                } elseif (! $tinta && $inicio !== null) {
                    if ($x - $inicio >= $largoMinimo) {
                        $tramos[] = ['y' => $y, 'x0' => $inicio, 'x1' => $x];
                    }

                    $inicio = null;
                }
            }
        }

        imagedestroy($imagen);

        return $this->agrupar($tramos, $grosorMaximo, $mmPorPx);
    }

    /**
     * Une tramos de filas consecutivas que se solapan (una línea de 2–3 px
     * de grosor) y descarta los bloques gruesos.
     *
     * @param  list<array{y: int, x0: int, x1: int}>  $tramos
     * @return list<Linea>
     */
    private function agrupar(array $tramos, int $grosorMaximo, float $mmPorPx): array
    {
        $grupos = [];

        foreach ($tramos as $tramo) {
            $unido = false;

            foreach ($grupos as &$grupo) {
                if ($tramo['y'] - $grupo['y1'] <= 1 && $tramo['x0'] < $grupo['x1'] && $tramo['x1'] > $grupo['x0']) {
                    $grupo['y1'] = $tramo['y'];
                    $grupo['x0'] = min($grupo['x0'], $tramo['x0']);
                    $grupo['x1'] = max($grupo['x1'], $tramo['x1']);
                    $unido = true;
                    break;
                }
            }
            unset($grupo);

            if (! $unido) {
                $grupos[] = ['y0' => $tramo['y'], 'y1' => $tramo['y'], 'x0' => $tramo['x0'], 'x1' => $tramo['x1']];
            }
        }

        $lineas = [];

        foreach ($grupos as $grupo) {
            if ($grosorMaximo < $grupo['y1'] - $grupo['y0'] + 1) {
                continue;
            }

            $lineas[] = [
                'x' => round($grupo['x0'] * $mmPorPx, 2),
                'y' => round((($grupo['y0'] + $grupo['y1']) / 2) * $mmPorPx, 2),
                'ancho' => round(($grupo['x1'] - $grupo['x0']) * $mmPorPx, 2),
            ];
        }

        return $lineas;
    }

    private function esTinta(\GdImage $imagen, int $x, int $y): bool
    {
        $color = imagecolorat($imagen, $x, $y);
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) < self::TINTA;
    }
}
