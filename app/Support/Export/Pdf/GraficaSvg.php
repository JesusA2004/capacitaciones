<?php

namespace App\Support\Export\Pdf;

/**
 * Gráficas SVG reales para los reportes que imprime Chrome (Browsershot):
 * barras horizontales, dona y líneas. ChartRenderer sigue siendo la opción para
 * DomPDF, que no dibuja SVG con arcos de forma confiable. Los colores salen de
 * config/reportes.php; nada de hex de marca aquí.
 */
final class GraficaSvg
{
    /**
     * @param  list<string>  $categorias
     * @param  list<float>  $valores
     */
    public static function render(string $tipo, array $categorias, array $valores, string $colorAcento): string
    {
        if ($categorias === [] || array_sum($valores) === 0.0) {
            return '<p class="grafica-vacia">Sin datos para graficar.</p>';
        }

        return match ($tipo) {
            'pastel' => self::dona($categorias, $valores, $colorAcento),
            'lineas' => self::lineas($categorias, $valores, $colorAcento),
            default => self::barras($categorias, $valores, $colorAcento),
        };
    }

    /**
     * @param  list<string>  $categorias
     * @param  list<float>  $valores
     */
    private static function barras(array $categorias, array $valores, string $color): string
    {
        $alto = 26;
        $ancho = 720;
        $margen = 170;
        $maximo = max(1.0, ...$valores);
        $total = count($categorias) * $alto + 6;
        $svg = sprintf('<svg viewBox="0 0 %d %d" width="100%%" role="img" xmlns="http://www.w3.org/2000/svg">', $ancho, $total);

        foreach ($categorias as $i => $categoria) {
            $y = $i * $alto + 4;
            $largo = max(($valores[$i] / $maximo) * ($ancho - $margen - 70), $valores[$i] > 0 ? 3.0 : 0.0);
            $svg .= sprintf('<text x="%d" y="%d" text-anchor="end" font-size="11" fill="#303A38">%s</text>', $margen - 10, $y + 13, e(self::corta($categoria, 26)));
            $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="16" rx="4" fill="#F3EDE1"/>', $margen, $y + 1, $ancho - $margen - 70);
            $svg .= sprintf('<rect x="%d" y="%d" width="%.1F" height="16" rx="4" fill="%s"/>', $margen, $y + 1, $largo, e($color));
            $svg .= sprintf('<text x="%d" y="%d" font-size="11" font-weight="700" fill="#303A38">%s</text>', $ancho - 62, $y + 13, e(self::numero($valores[$i])));
        }

        return $svg.'</svg>';
    }

    /**
     * @param  list<string>  $categorias
     * @param  list<float>  $valores
     */
    private static function dona(array $categorias, array $valores, string $color): string
    {
        $paleta = self::paleta($color);
        $total = array_sum($valores) ?: 1.0;
        $cx = 110.0;
        $cy = 110.0;
        $radio = 78.0;
        $grosor = 34.0;
        $circunferencia = 2 * M_PI * $radio;
        $acumulado = 0.0;
        $filasLeyenda = max(count($categorias), 1);
        $alto = max(220, $filasLeyenda * 22 + 10);
        $svg = sprintf('<svg viewBox="0 0 720 %d" width="100%%" role="img" xmlns="http://www.w3.org/2000/svg">', $alto);

        foreach ($valores as $i => $valor) {
            $fraccion = $valor / $total;
            $tono = $paleta[$i % count($paleta)];
            $svg .= sprintf(
                '<circle cx="%.1F" cy="%.1F" r="%.1F" fill="none" stroke="%s" stroke-width="%.1F" stroke-dasharray="%.3F %.3F" stroke-dashoffset="%.3F" transform="rotate(-90 %.1F %.1F)"/>',
                $cx, $cy, $radio, e($tono), $grosor, $fraccion * $circunferencia, $circunferencia, -$acumulado * $circunferencia, $cx, $cy,
            );
            $acumulado += $fraccion;
            $y = $i * 22 + 18;
            $svg .= sprintf('<rect x="250" y="%d" width="12" height="12" rx="3" fill="%s"/>', $y - 10, e($tono));
            $svg .= sprintf('<text x="270" y="%d" font-size="11" fill="#303A38">%s</text>', $y, e(self::corta($categorias[$i], 44)));
            $svg .= sprintf('<text x="710" y="%d" text-anchor="end" font-size="11" font-weight="700" fill="#303A38">%s · %s%%</text>', $y, e(self::numero($valor)), number_format($fraccion * 100, 1));
        }

        $svg .= sprintf('<text x="%.1F" y="%.1F" text-anchor="middle" font-size="20" font-weight="700" fill="#0D3E43">%s</text>', $cx, $cy + 7, e(self::numero($total)));

        return $svg.'</svg>';
    }

    /**
     * @param  list<string>  $categorias
     * @param  list<float>  $valores
     */
    private static function lineas(array $categorias, array $valores, string $color): string
    {
        $ancho = 720;
        $alto = 240;
        [$izquierda, $derecha, $arriba, $abajo] = [40, 16, 16, 40];
        $maximo = max(1.0, ...$valores);
        $cantidad = max(count($valores) - 1, 1);
        $x = fn (int $i): float => $izquierda + ($i / $cantidad) * ($ancho - $izquierda - $derecha);
        $y = fn (float $v): float => $arriba + (1 - $v / $maximo) * ($alto - $arriba - $abajo);
        $svg = sprintf('<svg viewBox="0 0 %d %d" width="100%%" role="img" xmlns="http://www.w3.org/2000/svg">', $ancho, $alto);

        foreach ([0, 0.5, 1] as $paso) {
            $ly = $y($maximo * $paso);
            $svg .= sprintf('<line x1="%d" x2="%d" y1="%.1F" y2="%.1F" stroke="#E7DED1" stroke-width="1"/>', $izquierda, $ancho - $derecha, $ly, $ly);
            $svg .= sprintf('<text x="%d" y="%.1F" text-anchor="end" font-size="10" fill="#707874">%s</text>', $izquierda - 6, $ly + 3, e(self::numero($maximo * $paso)));
        }

        $puntos = [];

        foreach ($valores as $i => $valor) {
            $puntos[] = sprintf('%.1F,%.1F', $x($i), $y($valor));
        }

        $svg .= sprintf('<polyline fill="none" stroke="%s" stroke-width="2.5" stroke-linejoin="round" points="%s"/>', e($color), implode(' ', $puntos));
        $salto = max((int) ceil(count($valores) / 12), 1);

        foreach ($valores as $i => $valor) {
            $svg .= sprintf('<circle cx="%.1F" cy="%.1F" r="3.5" fill="%s"/>', $x($i), $y($valor), e($color));

            if ($i % $salto === 0) {
                $svg .= sprintf('<text x="%.1F" y="%d" text-anchor="middle" font-size="9.5" fill="#707874">%s</text>', $x($i), $alto - 22, e(self::corta($categorias[$i], 12)));
            }
        }

        return $svg.'</svg>';
    }

    /**
     * @return list<string>
     */
    private static function paleta(string $color): array
    {
        $base = config('reportes.grafica');
        $base = is_array($base) ? array_map('strval', array_values($base)) : [];

        return array_values(array_unique([$color, ...$base]));
    }

    private static function corta(string $texto, int $largo): string
    {
        return mb_strlen($texto) > $largo ? mb_substr($texto, 0, $largo - 1).'…' : $texto;
    }

    private static function numero(float $valor): string
    {
        return number_format($valor, floor($valor) === $valor ? 0 : 2);
    }
}
