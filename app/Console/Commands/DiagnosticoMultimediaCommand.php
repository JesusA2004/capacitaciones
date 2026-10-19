<?php

namespace App\Console\Commands;

use App\Models\RecursoMultimedia;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Diagnóstico de la biblioteca multimedia (video/documentos del disco «nas» +
 * FFmpeg + X-Accel-Redirect + cola de procesamiento). Complementa
 * `people:diagnostico` (que solo prueba una escritura genérica al NAS) con
 * todo lo que necesita el pipeline de video (docs/PROCESAMIENTO_VIDEO.md,
 * sección 8). No destructivo: la única escritura es un archivo temporal de
 * prueba que se crea, se verifica y se borra. Nunca imprime secretos
 * (credenciales SFTP del NAS, etc.), solo si cada comprobación pasa o falla.
 *
 *   php artisan people:diagnostico-multimedia
 *   php artisan people:diagnostico-multimedia 42   (verifica los archivos del recurso 42)
 *   sudo -u www-data php artisan people:diagnostico-multimedia   (como corre PHP-FPM)
 */
class DiagnosticoMultimediaCommand extends Command
{
    protected $signature = 'people:diagnostico-multimedia {recurso? : ID de un RecursoMultimedia de ejemplo para verificar sus archivos}';

    protected $description = 'Revisa el disco NAS multimedia, FFmpeg/FFprobe, X-Accel-Redirect y la cola de procesamiento de video (solo escribe un archivo temporal de prueba)';

    private bool $huboProblemas = false;

    public function handle(MediaStorageService $storage): int
    {
        $this->revisarDisco($storage);
        $this->revisarAcceso($storage);
        $this->revisarFfmpeg();
        $this->revisarXAccel();
        $this->revisarCola();
        $this->revisarRecursoEjemplo($storage);

        if ($this->huboProblemas) {
            $this->newLine();
            $this->warn('Diagnóstico multimedia terminado con FALLAS — revisa lo marcado arriba.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Diagnóstico multimedia terminado sin problemas.');

        return self::SUCCESS;
    }

    private function revisarDisco(MediaStorageService $storage): void
    {
        $this->line('<fg=blue>Disco multimedia (config/media.php → disco «nas»)</>');

        $nombreDisco = (string) config('media.disk');
        $driver = config("filesystems.disks.{$nombreDisco}.driver");

        if (! is_string($driver) || $driver === '') {
            $this->fallo("El disco multimedia «{$nombreDisco}» no está configurado en config/filesystems.php.");

            return;
        }

        $this->ok(sprintf('Disco «%s» configurado (driver: %s).', $nombreDisco, $driver));

        if ($storage->esDiscoLocal()) {
            // Raíz REAL que usa el adaptador (no la de config, que podría estar
            // desalineada): si el NAS no está montado en NAS_ROOT, el driver
            // local escribiría en silencio en una carpeta local vacía — por eso
            // se confirma que la raíz exista como directorio.
            $raiz = rtrim($storage->rutaLocalAbsoluta(''), '/\\');

            if ($raiz === '') {
                $this->fallo('El disco multimedia local no tiene raíz (NAS_ROOT) configurada.');
            } elseif (! is_dir($raiz)) {
                $this->fallo(sprintf('La raíz del NAS «%s» (NAS_ROOT) no existe o no es accesible — revisa el montaje NFS/SMB.', $raiz));
            } else {
                $this->ok(sprintf('Raíz del NAS accesible: %s', $raiz));
            }
        } else {
            $this->ok(sprintf('Driver remoto (%s): la raíz se valida con la lectura/escritura de prueba.', $driver));
        }
    }

    private function revisarAcceso(MediaStorageService $storage): void
    {
        $this->newLine();
        $this->line('<fg=blue>Lectura y escritura en el NAS</>');

        $ruta = 'diagnostico/'.uniqid('media-write-test-', true).'.tmp';
        $contenido = 'ok-'.now()->timestamp;

        try {
            // El disco «nas» tiene throw=false: put() devuelve false al fallar
            // en vez de lanzar. Se confirma el retorno Y la existencia para no
            // reportar un falso positivo.
            $escrito = $storage->disco()->put($ruta, $contenido);

            if ($escrito === false || ! $storage->existe($ruta)) {
                $this->fallo('Escritura al NAS NO confirmada (put() devolvió false o el archivo no quedó escrito) — revisa permisos, espacio y montaje.');

                return;
            }

            $this->ok('Escritura al NAS confirmada (archivo temporal creado y verificado).');

            $leido = $storage->disco()->get($ruta);

            $leido === $contenido
                ? $this->ok('Lectura del NAS correcta (el contenido escrito se recuperó íntegro).')
                : $this->fallo('La lectura del NAS devolvió un contenido distinto al escrito.');

            $storage->eliminar($ruta);
            $this->ok('Archivo temporal de prueba borrado.');
        } catch (Throwable $e) {
            $this->fallo('El NAS no respondió a la prueba de lectura/escritura: '.$e->getMessage());
        }
    }

    private function revisarFfmpeg(): void
    {
        $this->newLine();
        $this->line('<fg=blue>FFmpeg / FFprobe (config/media.php)</>');

        $this->revisarBinario('ffmpeg', (string) config('media.ffmpeg_bin'), 'FFMPEG_BIN');
        $this->revisarBinario('ffprobe', (string) config('media.ffprobe_bin'), 'FFPROBE_BIN');
    }

    private function revisarBinario(string $etiqueta, string $binario, string $variable): void
    {
        if ($binario === '') {
            $this->fallo("{$etiqueta} no está configurado ({$variable}).");

            return;
        }

        try {
            $resultado = Process::timeout(15)->run([$binario, '-version']);
        } catch (Throwable $e) {
            $this->fallo(sprintf('No se pudo ejecutar %s («%s»): %s', $etiqueta, $binario, $e->getMessage()));

            return;
        }

        if (! $resultado->successful()) {
            $this->fallo(sprintf('%s («%s») no respondió a -version — ¿está instalado y en el PATH del usuario del servidor web?', $etiqueta, $binario));

            return;
        }

        $primeraLinea = trim(strtok($resultado->output(), "\n") ?: $etiqueta);
        $this->ok(sprintf('%s disponible: %s', $etiqueta, $primeraLinea));
    }

    private function revisarXAccel(): void
    {
        $this->newLine();
        $this->line('<fg=blue>X-Accel-Redirect (Nginx)</>');

        $activo = (bool) config('media.x_accel_redirect');
        $prefijo = (string) config('media.x_accel_internal_prefix');

        if (! $activo) {
            $this->ok('X-Accel-Redirect desactivado (MEDIA_X_ACCEL_REDIRECT=false): el video se transmite por PHP (correcto sin Nginx delante, solo más lento bajo carga).');

            return;
        }

        if ($prefijo === '') {
            $this->fallo('X-Accel-Redirect activo pero MEDIA_X_ACCEL_INTERNAL_PREFIX está vacío.');

            return;
        }

        $this->ok(sprintf('X-Accel-Redirect activo con prefijo interno «%s».', $prefijo));
        $this->line(sprintf('    Recomendación: aplica deploy/nginx/multimedia.conf; el «location %s/» (internal) debe apuntar (alias) a NAS_ROOT, y el origen de CORS debe ser MEDIA_ALLOWED_ORIGIN (%s).', $prefijo, (string) config('media.allowed_origin')));
    }

    private function revisarCola(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Cola de procesamiento de video (ProcesarVideoJob)</>');

        $conexion = config('queue.default');
        $this->ok(sprintf('Conexión de cola: %s.', is_string($conexion) ? $conexion : '—'));

        if ($conexion === 'sync' && app()->isProduction()) {
            $this->fallo('QUEUE_CONNECTION=sync en producción: cada video se procesa dentro del request de subida (bloqueante). Usa database/redis con un worker.');
        }

        try {
            if (Schema::hasTable('failed_jobs')) {
                $fallidos = DB::table('failed_jobs')->count();
                $fallidos > 0
                    ? $this->fallo(sprintf('%d trabajo(s) fallido(s) en la cola — revisa `php artisan queue:failed` (puede incluir conversiones de video).', $fallidos))
                    : $this->ok('Sin trabajos fallidos.');
            }

            if ($conexion === 'database' && Schema::hasTable('jobs')) {
                $viejos = DB::table('jobs')->where('created_at', '<', now()->subMinutes(15)->getTimestamp())->count();
                $viejos > 0
                    ? $this->fallo(sprintf('%d trabajo(s) esperando más de 15 min — ¿está corriendo `php artisan queue:work`?', $viejos))
                    : $this->ok('No hay trabajos atorados en la cola.');
            }
        } catch (Throwable $e) {
            $this->fallo('No se pudo leer el estado de la cola: '.$e->getMessage());
        }
    }

    private function revisarRecursoEjemplo(MediaStorageService $storage): void
    {
        $idArgumento = $this->argument('recurso');

        if ($idArgumento === null) {
            return;
        }

        $this->newLine();
        $this->line('<fg=blue>Archivos de un recurso de ejemplo</>');

        if (! Schema::hasTable('recursos_multimedia')) {
            $this->fallo('Falta la tabla «recursos_multimedia» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $recurso = RecursoMultimedia::query()->where('id', (int) $idArgumento)->first();

        if ($recurso === null) {
            $this->fallo(sprintf('No existe un RecursoMultimedia con id %s.', $idArgumento));

            return;
        }

        $this->ok(sprintf('Recurso #%d «%s» (%s, estado: %s).', $recurso->id, $recurso->nombre_original, $recurso->tipo->etiqueta(), $recurso->estado->etiqueta()));

        // Original
        $storage->existe($recurso->ruta_original)
            ? $this->ok('Archivo original presente en el NAS.')
            : $this->fallo(sprintf('El archivo original NO existe en el NAS (%s) — el recurso apunta a un archivo inexistente.', $recurso->ruta_original));

        // Manifiesto HLS + segmentos (solo videos ya convertidos)
        if ($recurso->ruta_hls_manifiesto !== null && $recurso->ruta_hls_manifiesto !== '') {
            $storage->existe($recurso->ruta_hls_manifiesto)
                ? $this->ok('Manifiesto HLS maestro presente.')
                : $this->fallo(sprintf('El manifiesto HLS NO existe en el NAS (%s).', $recurso->ruta_hls_manifiesto));

            $carpetaHls = $storage->rutaHlsCarpeta($recurso->nombre_interno);

            try {
                $archivos = $storage->disco()->files($carpetaHls);
                $segmentos = count(array_filter($archivos, static fn (string $a): bool => str_ends_with($a, '.ts')));
                $playlists = count(array_filter($archivos, static fn (string $a): bool => str_ends_with($a, '.m3u8')));

                $segmentos > 0 && $playlists > 0
                    ? $this->ok(sprintf('HLS con %d segmento(s) .ts y %d lista(s) .m3u8.', $segmentos, $playlists))
                    : $this->fallo(sprintf('La carpeta HLS (%s) no tiene segmentos .ts y/o listas .m3u8 (%d/%d).', $carpetaHls, $segmentos, $playlists));
            } catch (Throwable $e) {
                $this->fallo('No se pudo listar la carpeta HLS: '.$e->getMessage());
            }
        } else {
            $this->ok('Recurso sin HLS (no es un video procesado o aún está pendiente).');
        }

        // Miniatura
        if ($recurso->ruta_miniatura !== null && $recurso->ruta_miniatura !== '') {
            $storage->existe($recurso->ruta_miniatura)
                ? $this->ok('Miniatura presente en el NAS.')
                : $this->fallo(sprintf('La miniatura NO existe en el NAS (%s).', $recurso->ruta_miniatura));
        }
    }

    private function ok(string $mensaje): void
    {
        $this->line("  <fg=green>✓</> {$mensaje}");
    }

    private function fallo(string $mensaje): void
    {
        $this->huboProblemas = true;
        $this->line("  <fg=red>✗</> {$mensaje}");
    }
}
