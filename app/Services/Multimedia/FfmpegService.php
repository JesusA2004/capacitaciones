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
     * @return array{duracion_segundos: int|null, ancho: int|null, alto: int|null}
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
     * Convierte el video a HLS, generando solo las resoluciones candidatas
     * que sean menores o iguales a la altura original (nunca se escala hacia
     * arriba), mas un manifiesto maestro que las referencia a todas.
     *
     * @param  array<int, int>  $resolucionesCandidatas  alturas en pixeles (p. ej. 360, 480, 720, 1080)
     * @return array<int, int> alturas realmente generadas
     */
    public function convertirAHls(string $rutaVideo, string $carpetaSalida, int $alturaOriginal, array $resolucionesCandidatas, int $segmentoSegundos): array
    {
        if (! is_dir($carpetaSalida) && ! mkdir($carpetaSalida, 0755, true) && ! is_dir($carpetaSalida)) {
            throw new \RuntimeException("No se pudo crear la carpeta de salida HLS: {$carpetaSalida}");
        }

        $alturasAGenerar = array_values(array_filter($resolucionesCandidatas, fn (int $altura) => $altura <= $alturaOriginal));

        if ($alturasAGenerar === [] && $alturaOriginal > 0) {
            $alturasAGenerar = [$alturaOriginal];
        }

        $variantes = [];

        foreach ($alturasAGenerar as $altura) {
            $nombreSalida = "{$altura}p.m3u8";

            $resultado = Process::timeout(1800)->run([
                config('media.ffmpeg_bin'), '-y', '-i', $rutaVideo,
                '-vf', "scale=-2:{$altura}",
                '-c:a', 'aac', '-ar', '48000',
                '-c:v', 'h264', '-profile:v', 'main', '-crf', '20', '-sc_threshold', '0',
                '-g', '48', '-keyint_min', '48',
                '-hls_time', (string) $segmentoSegundos, '-hls_playlist_type', 'vod',
                '-hls_segment_filename', "{$carpetaSalida}/{$altura}p_%03d.ts",
                "{$carpetaSalida}/{$nombreSalida}",
            ]);

            if ($resultado->failed()) {
                throw new \RuntimeException("Falló la conversión HLS a {$altura}p: {$resultado->errorOutput()}");
            }

            $variantes[$altura] = $nombreSalida;
        }

        $this->generarManifiestoMaestro($carpetaSalida, $variantes);

        return array_keys($variantes);
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
