<?php

namespace App\Services\Multimedia;

/**
 * Reescribe los manifiestos HLS generados por FfmpegService para que:
 * 1) nunca expongan rutas reales del disco NAS (cada variante/segmento se
 *    referencia a traves de una ruta firmada de corta duracion), y
 * 2) las variantes solo listen los segmentos hasta el limite de avance
 *    permitido del usuario, de modo que el anti-adelanto de video quede
 *    respaldado por el servidor y no solo oculto en el reproductor.
 *
 * Mientras el usuario no haya "desbloqueado" el video completo, la variante
 * se sirve sin #EXT-X-ENDLIST y como PLAYLIST-TYPE:EVENT: eso le indica a
 * hls.js que puede haber mas segmentos despues y que debe volver a pedir el
 * manifiesto periodicamente, en vez de asumir que ya tiene todo el video.
 */
class ManifiestoHlsService
{
    /**
     * @param  callable(int): string  $urlVariante
     */
    public function reescribirMaestro(string $contenido, callable $urlVariante): string
    {
        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido)) ?: [];
        $resultado = [];

        foreach ($lineas as $linea) {
            if ($linea === '' || str_starts_with($linea, '#')) {
                $resultado[] = $linea;

                continue;
            }

            preg_match('/^(\d+)p\.m3u8$/', $linea, $coincidencias);
            $resultado[] = $urlVariante((int) ($coincidencias[1] ?? 0));
        }

        return implode("\n", $resultado)."\n";
    }

    /**
     * @param  callable(int, string): string  $urlSegmento
     * @return array{contenido: string, completo: bool}
     */
    public function truncarVariante(string $contenido, int $segundoLimite, callable $urlSegmento): array
    {
        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido)) ?: [];
        $encabezado = [];
        $entradas = [];
        $duracionPendiente = null;

        foreach ($lineas as $linea) {
            if (str_starts_with($linea, '#EXTINF:')) {
                $duracionPendiente = (float) trim(substr($linea, 8), ", \t");

                continue;
            }

            if ($linea === '' || str_starts_with($linea, '#EXT-X-ENDLIST') || str_starts_with($linea, '#EXT-X-PLAYLIST-TYPE')) {
                continue;
            }

            if (str_starts_with($linea, '#')) {
                $encabezado[] = $linea;

                continue;
            }

            $entradas[] = ['duracion' => $duracionPendiente ?? 0.0, 'archivo' => $linea];
            $duracionPendiente = null;
        }

        $tiempoAcumulado = 0.0;
        $segmentosIncluidos = [];

        foreach ($entradas as $indice => $entrada) {
            if ($tiempoAcumulado >= $segundoLimite) {
                break;
            }

            $segmentosIncluidos[] = ['indice' => $indice, 'duracion' => $entrada['duracion'], 'archivo' => $entrada['archivo']];
            $tiempoAcumulado += $entrada['duracion'];
        }

        $completo = count($segmentosIncluidos) === count($entradas);

        $salida = [...$encabezado, '#EXT-X-PLAYLIST-TYPE:'.($completo ? 'VOD' : 'EVENT')];

        foreach ($segmentosIncluidos as $segmento) {
            $salida[] = '#EXTINF:'.$segmento['duracion'].',';
            $salida[] = $urlSegmento($segmento['indice'], $segmento['archivo']);
        }

        if ($completo) {
            $salida[] = '#EXT-X-ENDLIST';
        }

        return ['contenido' => implode("\n", $salida)."\n", 'completo' => $completo];
    }
}
