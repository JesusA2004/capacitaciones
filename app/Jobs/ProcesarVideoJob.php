<?php

namespace App\Jobs;

use App\Enums\EstadoMultimedia;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\FfmpegService;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Procesamiento pesado de un video subido: obtiene duracion/resolucion con
 * ffprobe, genera una miniatura y convierte a HLS (solo con las resoluciones
 * <= a la original). Se ejecuta en cola para no bloquear el request de subida.
 *
 * Idempotente: si el recurso ya no esta en estado "pendiente" (por ejemplo,
 * porque un reintento anterior ya lo dejo "disponible" o porque se descarto),
 * no vuelve a procesarlo.
 */
class ProcesarVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public int $backoff = 30;

    public function __construct(public readonly RecursoMultimedia $recurso) {}

    public function handle(MediaStorageService $storage, FfmpegService $ffmpeg): void
    {
        $recurso = $this->recurso->fresh();

        if (! $recurso || $recurso->estado !== EstadoMultimedia::Pendiente) {
            return;
        }

        $recurso->update(['estado' => EstadoMultimedia::Procesando->value]);

        $rutaLocalOriginal = null;
        $carpetaHlsLocal = null;
        $rutaMiniaturaLocal = null;
        $esTemporalDescargado = false;

        try {
            if ($storage->esDiscoLocal()) {
                $rutaLocalOriginal = $storage->rutaLocalAbsoluta($recurso->ruta_original);
            } else {
                $rutaLocalOriginal = $storage->descargarATemporal($recurso->ruta_original);
                $esTemporalDescargado = true;
            }

            $info = $ffmpeg->inspeccionar($rutaLocalOriginal);
            $altura = (int) ($info['alto'] ?? 0);

            $carpetaHlsLocal = sys_get_temp_dir().'/hls_'.$recurso->nombre_interno;
            $ffmpeg->convertirAHls(
                $rutaLocalOriginal,
                $carpetaHlsLocal,
                $altura,
                config('media.video.resoluciones'),
                config('media.video.segmento_segundos'),
            );

            $rutaMiniaturaLocal = sys_get_temp_dir().'/'.$recurso->nombre_interno.'.jpg';
            $ffmpeg->generarMiniatura($rutaLocalOriginal, $rutaMiniaturaLocal);

            $carpetaHlsDestino = $storage->rutaHlsCarpeta($recurso->nombre_interno);

            foreach (glob("{$carpetaHlsLocal}/*") ?: [] as $archivoLocal) {
                $storage->guardar($archivoLocal, "{$carpetaHlsDestino}/".basename($archivoLocal));
            }

            $storage->guardar($rutaMiniaturaLocal, $storage->rutaMiniatura($recurso->nombre_interno));

            $recurso->update([
                'estado' => EstadoMultimedia::Disponible->value,
                'duracion_segundos' => $info['duracion_segundos'],
                'resolucion_original' => $info['ancho'] && $info['alto'] ? "{$info['ancho']}x{$info['alto']}" : null,
                'ruta_hls_manifiesto' => "{$carpetaHlsDestino}/master.m3u8",
                'ruta_miniatura' => $storage->rutaMiniatura($recurso->nombre_interno),
                'error_procesamiento' => null,
            ]);
        } catch (\Throwable $excepcion) {
            $recurso->update([
                'estado' => EstadoMultimedia::Error->value,
                'error_procesamiento' => $excepcion->getMessage(),
            ]);

            report($excepcion);
        } finally {
            $this->limpiarTemporales($rutaMiniaturaLocal, $carpetaHlsLocal, $esTemporalDescargado ? $rutaLocalOriginal : null);
        }
    }

    private function limpiarTemporales(?string $rutaMiniatura, ?string $carpetaHls, ?string $rutaOriginalTemporal): void
    {
        if ($rutaMiniatura && file_exists($rutaMiniatura)) {
            @unlink($rutaMiniatura);
        }

        if ($carpetaHls && is_dir($carpetaHls)) {
            foreach (glob("{$carpetaHls}/*") ?: [] as $archivo) {
                @unlink($archivo);
            }
            @rmdir($carpetaHls);
        }

        if ($rutaOriginalTemporal && file_exists($rutaOriginalTemporal)) {
            @unlink($rutaOriginalTemporal);
        }
    }
}
