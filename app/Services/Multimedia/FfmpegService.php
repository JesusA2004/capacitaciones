<?php

namespace App\Services\Multimedia;

use Illuminate\Support\Facades\Process;

/**
 * Envoltura delgada sobre los binarios de FFmpeg/FFprobe configurados en
 * config/media.php. No conoce nada del disco NAS: trabaja siempre con rutas
 * locales absolutas (ver MediaStorageService::rutaLocalAbsoluta/descargarATemporal).
 */
class FfmpegService
{
    /**
     * @return array{duracion_segundos: int|null, ancho: int|null, alto: int|null, tiene_audio: bool}
     */
    public function inspeccionar(string $rutaLocal): array
    {
        $resultado = Process::timeout(60)->run([
            config('media.ffprobe_bin'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $rutaLocal,
        ]);

        if ($resultado->failed()) {
            throw new \RuntimeException("ffprobe falló: {$resultado->errorOutput()}");
        }

        /** @var array{format?: array<string, mixed>, streams?: array<int, array<string, mixed>>} $datos */
        $datos = json_decode($resultado->output(), true) ?? [];
        $streams = $datos['streams'] ?? [];
        $streamVideo = collect($streams)->first(fn (array $stream) => ($stream['codec_type'] ?? null) === 'video');

        return [
            'duracion_segundos' => isset($datos['format']['duration']) ? (int) round((float) $datos['format']['duration']) : null,
            'ancho' => $streamVideo['width'] ?? null,
            'alto' => $streamVideo['height'] ?? null,
            // Sin pista de audio no se puede mapear 0:a:0 en el HLS.
            'tiene_audio' => collect($streams)->contains(fn (array $stream) => ($stream['codec_type'] ?? null) === 'audio'),
        ];
    }

    public function generarMiniatura(string $rutaVideo, string $rutaMiniatura, int $segundo = 3): void
    {
        $resultado = Process::timeout(60)->run([
            config('media.ffmpeg_bin'), '-y', '-ss', (string) $segundo, '-i', $rutaVideo, '-frames:v', '1', '-q:v', '2', $rutaMiniatura,
        ]);

        if ($resultado->failed()) {
            throw new \RuntimeException("No se pudo generar la miniatura: {$resultado->errorOutput()}");
        }
    }

    /**
     * Convierte el video a HLS en UNA sola pasada de FFmpeg: decodifica el
     * original una vez y codifica todas las variantes a la vez (split +
     * var_stream_map), en lugar de una codificación completa por resolución.
     * Solo genera las resoluciones candidatas menores o iguales a la altura
     * original (nunca escala hacia arriba). Preset/CRF configurables
     * (config('media.video.preset'/'crf'); «veryfast» + CRF 23 por defecto:
     * varias veces más rápido que «medium» + CRF 20, con calidad de sobra
     * para una explicación). Keyframes forzados en cada límite de segmento,
     * para que cada segmento empiece en keyframe.
     *
     * Nombres de salida (los esperan ManifiestoHlsService y los endpoints de
     * segmentos): {altura}p.m3u8 y {altura}p_NNN.ts, más master.m3u8.
     *
     * @param  array<int, int>  $resolucionesCandidatas  alturas en pixeles (p. ej. 480, 720)
     * @return array<int, int> alturas realmente generadas
     */
    public function convertirAHls(string $rutaVideo, string $carpetaSalida, int $alturaOriginal, array $resolucionesCandidatas, int $segmentoSegundos, bool $conAudio = true): array
    {
        if (! is_dir($carpetaSalida) && ! mkdir($carpetaSalida, 0755, true) && ! is_dir($carpetaSalida)) {
            throw new \RuntimeException("No se pudo crear la carpeta de salida HLS: {$carpetaSalida}");
        }

        $alturasAGenerar = array_values(array_unique(array_filter($resolucionesCandidatas, fn (int $altura) => $altura <= $alturaOriginal)));
        sort($alturasAGenerar);

        if ($alturasAGenerar === [] && $alturaOriginal > 0) {
            $alturasAGenerar = [$alturaOriginal];
        }

        if ($alturasAGenerar === []) {
            throw new \RuntimeException('No se pudo determinar la resolución del video para convertirlo a HLS.');
        }

        $resultado = Process::timeout(3500)->run($this->comandoHls($rutaVideo, $carpetaSalida, $alturasAGenerar, $segmentoSegundos, $conAudio));

        if ($resultado->failed()) {
            throw new \RuntimeException(sprintf(
                'Falló la conversión HLS (%s): %s',
                implode(', ', array_map(fn (int $altura) => sprintf('%dp', $altura), $alturasAGenerar)),
                $resultado->errorOutput(),
            ));
        }

        $variantes = [];

        foreach ($alturasAGenerar as $altura) {
            $variantes[$altura] = sprintf('%dp.m3u8', $altura);
        }

        $this->generarManifiestoMaestro($carpetaSalida, $variantes);

        return array_keys($variantes);
    }

    /**
     * Comando de FFmpeg de una sola pasada para todas las variantes HLS.
     *
     * @param  list<int>  $alturas
     * @return list<string>
     */
    public function comandoHls(string $rutaVideo, string $carpetaSalida, array $alturas, int $segmentoSegundos, bool $conAudio = true): array
    {
        $filtro = sprintf('[0:v]split=%d', count($alturas));

        foreach (array_keys($alturas) as $i) {
            $filtro .= sprintf('[v%d]', $i);
        }

        foreach ($alturas as $i => $altura) {
            $filtro .= sprintf(';[v%d]scale=-2:%d[v%dout]', $i, $altura, $i);
        }

        $comando = [
            (string) config('media.ffmpeg_bin'), '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $rutaVideo,
            '-filter_complex', $filtro,
        ];
        $mapa = [];

        foreach ($alturas as $i => $altura) {
            $comando[] = '-map';
            $comando[] = sprintf('[v%dout]', $i);

            if ($conAudio) {
                $comando[] = '-map';
                $comando[] = '0:a:0';
            }

            $mapa[] = $conAudio ? sprintf('v:%d,a:%d,name:%dp', $i, $i, $altura) : sprintf('v:%d,name:%dp', $i, $altura);
        }

        array_push(
            $comando,
            '-c:v', 'libx264',
            '-preset', (string) config('media.video.preset', 'veryfast'),
            '-crf', (string) config('media.video.crf', 23),
            '-profile:v', 'main', '-pix_fmt', 'yuv420p',
            '-threads', '0',
            '-sc_threshold', '0',
            '-force_key_frames', sprintf('expr:gte(t,n_forced*%d)', $segmentoSegundos),
        );

        if ($conAudio) {
            array_push($comando, '-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-ac', '2');
        }

        array_push(
            $comando,
            '-f', 'hls',
            '-hls_time', (string) $segmentoSegundos,
            '-hls_playlist_type', 'vod',
            '-hls_segment_filename', sprintf('%s/%%v_%%03d.ts', $carpetaSalida),
            '-var_stream_map', implode(' ', $mapa),
            sprintf('%s/%%v.m3u8', $carpetaSalida),
        );

        return $comando;
    }

    /**
     * @param  array<int, string>  $variantes  altura => nombre de archivo .m3u8
     */
    private function generarManifiestoMaestro(string $carpetaSalida, array $variantes): void
    {
        $anchoDeBandaPorAltura = [360 => 800_000, 480 => 1_400_000, 720 => 2_800_000, 1080 => 5_000_000];
        $lineas = ['#EXTM3U'];

        foreach ($variantes as $altura => $archivo) {
            $anchoDeBanda = $anchoDeBandaPorAltura[$altura] ?? 1_000_000;
            $lineas[] = "#EXT-X-STREAM-INF:BANDWIDTH={$anchoDeBanda},RESOLUTION=".$this->resolucionParaAltura($altura);
            $lineas[] = $archivo;
        }

        file_put_contents("{$carpetaSalida}/master.m3u8", implode("\n", $lineas)."\n");
    }

    private function resolucionParaAltura(int $altura): string
    {
        return match ($altura) {
            360 => '640x360',
            480 => '854x480',
            720 => '1280x720',
            1080 => '1920x1080',
            default => "?x{$altura}",
        };
    }
}
