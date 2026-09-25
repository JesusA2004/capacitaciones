<?php

namespace App\Services\Celebraciones;

use GdImage;

/**
 * Utilidades GD para las tarjetas de celebración (cumpleaños y aniversario):
 * texto centrado con ajuste de tamaño y envoltura (nunca se corta un
 * nombre), colores y assets de marca. Compartidas en vez de duplicarlas en
 * cada servicio de tarjeta.
 */
trait DibujoTarjeta
{
    /**
     * Asset de marca real del repositorio (logo, personaje): se busca en
     * public/images y luego en resources/js/assets/brand. null si no existe.
     */
    private function rutaAssetMarca(string $archivo): ?string
    {
        foreach ([public_path('images/'.$archivo), resource_path('js/assets/brand/'.$archivo)] as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    private function textoCentrado(GdImage $imagen, string $fuente, int $tamano, int $color, int $ancho, int $y, string $texto): void
    {
        $anchoTexto = $this->anchoTexto($fuente, $tamano, $texto);
        $x = (int) (($ancho - $anchoTexto) / 2);

        imagettftext($imagen, $tamano, 0, $x, $y, $color, $fuente, $texto);
    }

    /**
     * Igual que textoCentrado() pero con una sombra sutil semitransparente
     * debajo, para que el headline destaque más sobre el fondo degradado.
     */
    private function textoConSombra(GdImage $imagen, string $fuente, int $tamano, int $colorPrincipal, int $ancho, int $y, string $texto): void
    {
        $sombra = $this->colorRgba($imagen, 74, 52, 30, 75);
        $this->textoCentrado($imagen, $fuente, $tamano, $sombra, $ancho, $y + 3, $texto);
        $this->textoCentrado($imagen, $fuente, $tamano, $colorPrincipal, $ancho, $y, $texto);
    }

    /**
     * Dibuja un párrafo centrado, partiendo el texto en líneas que respetan
     * $anchoMaximo (soporta nombres y frases largas sin cortar contenido).
     * Si $altoMaximo se indica y el párrafo no cabe, la tipografía se
     * reduce de forma proporcional (nunca se trunca el texto) hasta que
     * quepa o se llegue a $tamanoMinimo.
     *
     * @return int La Y final, justo debajo de la última línea dibujada.
     */
    private function textoParrafo(
        GdImage $imagen,
        string $fuente,
        int $tamano,
        int $color,
        int $ancho,
        int $y,
        string $texto,
        int $anchoMaximo,
        int $interlineado,
        ?int $altoMaximo = null,
        int $tamanoMinimo = 18,
    ): int {
        $tamanoActual = $tamano;
        $interlineadoActual = $interlineado;
        $lineas = $this->partirLineas($fuente, $tamanoActual, $texto, $anchoMaximo);

        while ($altoMaximo !== null && $tamanoActual > $tamanoMinimo) {
            $alturaTotal = count($lineas) * $interlineadoActual;

            if ($alturaTotal <= $altoMaximo) {
                break;
            }

            $tamanoAnterior = $tamanoActual;
            $tamanoActual = max($tamanoMinimo, $tamanoActual - 2);
            $interlineadoActual = max(
                $tamanoActual + 8,
                (int) round($interlineadoActual * ($tamanoActual / $tamanoAnterior)),
            );
            $lineas = $this->partirLineas($fuente, $tamanoActual, $texto, $anchoMaximo);
        }

        foreach ($lineas as $indice => $linea) {
            $this->textoCentrado($imagen, $fuente, $tamanoActual, $color, $ancho, $y + ($indice * $interlineadoActual), $linea);
        }

        return $y + (count($lineas) * $interlineadoActual);
    }

    /**
     * @return list<string>
     */
    private function partirLineas(string $fuente, int $tamano, string $texto, int $anchoMaximo): array
    {
        $palabras = preg_split('/\s+/', trim($texto)) ?: [];
        $lineas = [];
        $lineaActual = '';

        foreach ($palabras as $palabra) {
            if ($palabra === '') {
                continue;
            }

            $intento = $lineaActual === '' ? $palabra : "{$lineaActual} {$palabra}";

            if ($lineaActual !== '' && $this->anchoTexto($fuente, $tamano, $intento) > $anchoMaximo) {
                $lineas[] = $lineaActual;
                $lineaActual = $palabra;
            } else {
                $lineaActual = $intento;
            }
        }

        if ($lineaActual !== '') {
            $lineas[] = $lineaActual;
        }

        return $lineas === [] ? [''] : $lineas;
    }

    /**
     * Centraliza imagettfbbox() (que puede regresar false) para no repetir
     * el manejo de ese caso en cada llamador.
     */
    private function anchoTexto(string $fuente, int $tamano, string $texto): int
    {
        $caja = @imagettfbbox($tamano, 0, $fuente, $texto);

        return $caja === false ? 0 : (int) abs($caja[4] - $caja[0]);
    }

    /**
     * imagecolorallocate() devuelve int|false segun sus stubs (falla solo si
     * la paleta de una imagen no-truecolor se agota, algo que no aplica a
     * las imagenes truecolor que usa este service); se centraliza aqui el
     * manejo de ese caso y el clamp 0-255 de cada canal.
     */
    private function colorRgb(GdImage $imagen, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($imagen, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));

        return $color === false ? 0 : $color;
    }

    private function colorTransparente(GdImage $imagen): int
    {
        $color = imagecolorallocatealpha($imagen, 0, 0, 0, 127);

        return $color === false ? 0 : $color;
    }

    /**
     * imagecolorallocatealpha() con el mismo clamp que colorRgb(); $alpha va
     * de 0 (opaco) a 127 (totalmente transparente), como espera GD.
     */
    private function colorRgba(GdImage $imagen, int $r, int $g, int $b, int $alpha): int
    {
        $color = imagecolorallocatealpha(
            $imagen,
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b)),
            max(0, min(127, $alpha)),
        );

        return $color === false ? 0 : $color;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexARgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return [255, 248, 231];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
