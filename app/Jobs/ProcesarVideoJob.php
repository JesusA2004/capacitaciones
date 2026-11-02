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
 * no vuelve a procesarlo. Excepción: un REINTENTO (attempts() > 1) de un
 * recurso que quedó en "procesando" — el worker anterior murió o se reinició a
 * la mitad — sí se reprocesa; si no, quedaría «procesando» para siempre.
 *
 * Va a su propia conexión/cola (config('media.cola'), worker
 * deploy/supervisor/people-media.conf) y, si se agota el tiempo o los
 * intentos, failed() deja el recurso en "error" con el motivo visible para RH.
 */
class ProcesarVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public int $backoff = 30;

    /** Un timeout marca el job como fallido (→ failed()), no lo reintenta a ciegas. */
    public bool $failOnTimeout = true;

    public function __construct(public readonly RecursoMultimedia $recurso)
    {
        $this->onConnection((string) config('media.cola.conexion'));
        $this->onQueue((string) config('media.cola.nombre'));
    }

    public function handle(MediaStorageService $storage, FfmpegService $ffmpeg): void
    {
        $recurso = $this->recurso->fresh();

        if (! $recurso) {
            return;
        }

        $reintentoAMedias = $recurso->estado === EstadoMultimedia::Procesando && $this->attempts() > 1;

        if ($recurso->estado !== EstadoMultimedia::Pendiente && ! $reintentoAMedias) {
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
                $info['tiene_audio'],
            );

            // Fotograma de portada: a los 3 s, o a la mitad si el video dura menos.
            $rutaMiniaturaLocal = sys_get_temp_dir().'/'.$recurso->nombre_interno.'.jpg';
            $ffmpeg->generarMiniatura($rutaLocalOriginal, $rutaMiniaturaLocal, min(3, intdiv((int) ($info['duracion_segundos'] ?? 0), 2)));

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

    /**
     * El job se agotó (timeout) o falló todos sus intentos fuera del
     * try/catch de handle(): el recurso nunca debe quedarse «procesando».
     */
    public function failed(?\Throwable $excepcion): void
    {
        $recurso = $this->recurso->fresh();

        if ($recurso === null || $recurso->estado === EstadoMultimedia::Disponible) {
            return;
        }

        $recurso->update([
            'estado' => EstadoMultimedia::Error->value,
            'error_procesamiento' => sprintf(
                'El procesamiento no terminó: %s',
                $excepcion?->getMessage() ?: 'el trabajo de la cola se agotó o se interrumpió.',
            ),
        ]);
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
