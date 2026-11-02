<?php

namespace App\Console\Commands;

use App\Enums\EstadoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\MediaStorageService;
use App\Services\Multimedia\ProcesamientoVideoService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Diagnóstico y recuperación de videos que no terminan de procesarse
 * (ProcesarVideoJob). Seguro en producción:
 *
 *  - Sin opciones solo LEE: conexión/cola de video configurada, trabajos
 *    esperando en la tabla `jobs` por cola, tamaño de la cola de video y los
 *    videos en pendiente / procesando / error con su antigüedad.
 *  - --procesar convierte AHORA, en este proceso (dispatchSync, sin cola), los
 *    videos «pendiente» y los «procesando» atorados (más de
 *    media.cola.procesando_atorado_minutos). Con --incluir-errores también los
 *    que quedaron en «error» (p. ej. tras instalar FFmpeg). Idempotente: uno
 *    ya «disponible» nunca se vuelve a convertir.
 *  - --recurso=ID limita todo a ese recurso.
 *
 *   php artisan people:videos-pendientes
 *   sudo -u www-data php artisan people:videos-pendientes --procesar
 *   sudo -u www-data php artisan people:videos-pendientes --procesar --incluir-errores --recurso=42
 */
class VideosPendientesCommand extends Command
{
    protected $signature = 'people:videos-pendientes
        {--procesar : Convierte ahora (sin cola) los videos pendientes o atorados}
        {--incluir-errores : Con --procesar, también reintenta los que quedaron en error}
        {--recurso= : ID de un RecursoMultimedia para limitar el reporte/proceso}';

    protected $description = 'Reporta almacenamiento y cola de video y los videos sin procesar; con --procesar los convierte en línea (seguro en producción)';

    private MediaStorageService $storage;

    public function handle(ProcesamientoVideoService $procesamiento, MediaStorageService $storage): int
    {
        $this->storage = $storage;
        $this->reportarAlmacenamiento();
        $this->newLine();
        $this->reportarCola($procesamiento);

        $videos = $this->videos($procesamiento);
        $this->reportarVideos($procesamiento, $videos);

        if (! $this->option('procesar')) {
            if ($videos->isNotEmpty()) {
                $this->newLine();
                $this->line('Para convertirlos ahora sin esperar a la cola: <comment>php artisan people:videos-pendientes --procesar</comment> (añade --incluir-errores para reintentar los fallidos).');
            }

            return self::SUCCESS;
        }

        return $this->procesar($procesamiento, $videos);
    }

    /**
     * Dónde quedan los videos: el disco multimedia (config('media.disk'),
     * «nas» = Synology montado en NAS_ROOT) guarda el original, los segmentos
     * HLS y la miniatura; FFmpeg solo trabaja en temporales locales que luego
     * se copian ahí. Aquí se ve la raíz real, si se puede escribir y el
     * espacio libre (la prueba de escritura completa: people:diagnostico-multimedia).
     */
    private function reportarAlmacenamiento(): void
    {
        $disco = (string) config('media.disk');
        $driver = (string) config("filesystems.disks.{$disco}.driver", '—');

        $this->line('<fg=blue>Almacenamiento de video</>');
        $this->line(sprintf('  Disco «%s» (driver: %s): original, HLS y miniatura se guardan aquí.', $disco, $driver));

        if (! $this->storage->esDiscoLocal()) {
            $this->line(sprintf('  Host remoto: %s', (string) config("filesystems.disks.{$disco}.host", '—')));

            return;
        }

        $raiz = rtrim($this->storage->rutaLocalAbsoluta(''), '/\\');
        $this->line(sprintf('  Raíz (NAS_ROOT): %s', $raiz));

        if (! is_dir($raiz)) {
            $this->error('  La raíz no existe: ¿está montado el Synology? (mount | grep people-storage)');

            return;
        }

        is_writable($raiz)
            ? $this->info('  Se puede escribir en la raíz.')
            : $this->error('  NO se puede escribir en la raíz con este usuario (corre el comando como www-data).');

        $libre = @disk_free_space($raiz);
        $this->line(sprintf('  Espacio libre: %s', $libre !== false ? sprintf('%.1f GB', $libre / 1_073_741_824) : 'no disponible'));
    }

    private function reportarCola(ProcesamientoVideoService $procesamiento): void
    {
        $conexion = (string) config('media.cola.conexion');
        $cola = (string) config('media.cola.nombre');

        $this->line('<fg=blue>Cola de video</>');
        $this->line(sprintf('  QUEUE_CONNECTION (cola general): %s', (string) config('queue.default')));
        $this->line(sprintf('  ProcesarVideoJob → conexión «%s», cola «%s» (retry_after: %s s)', $conexion, $cola, (string) config("queue.connections.{$conexion}.retry_after", '—')));
        $this->line(sprintf('  Worker esperado: php artisan queue:work %s --queue=%s --timeout=3600 (deploy/supervisor/people-media.conf)', $conexion, $cola));

        if ($conexion !== 'sync') {
            try {
                $this->line(sprintf('  Trabajos esperando en la cola de video: %d', Queue::connection($conexion)->size($cola)));
            } catch (Throwable $e) {
                $this->warn('  No se pudo leer el tamaño de la cola de video: '.$e->getMessage());
            }
        }

        try {
            $filas = $procesamiento->trabajosEnTablaJobs();
        } catch (Throwable $e) {
            $this->warn('  No se pudo leer la tabla jobs: '.$e->getMessage());
            $filas = [];
        }

        $this->newLine();
        $this->line('<fg=blue>Tabla `jobs` (database / media / notificaciones)</>');

        if ($filas === []) {
            $this->line('  Sin trabajos esperando.');
        } else {
            $this->table(
                ['Cola', 'Trabajos', 'De video', 'Más antiguo (min)'],
                array_map(fn (array $f): array => [$f['cola'], $f['total'], $f['videos'], $f['mas_antiguo_minutos']], $filas),
            );
        }
    }

    /**
     * @return Collection<int, RecursoMultimedia>
     */
    private function videos(ProcesamientoVideoService $procesamiento): Collection
    {
        $id = $this->option('recurso');

        if ($id === null || $id === '') {
            return $procesamiento->videosSinTerminar();
        }

        return RecursoMultimedia::query()
            ->where('id', (int) $id)
            ->where('tipo', TipoRecursoMultimedia::Video->value)
            ->get();
    }

    /**
     * @param  Collection<int, RecursoMultimedia>  $videos
     */
    private function reportarVideos(ProcesamientoVideoService $procesamiento, Collection $videos): void
    {
        $this->newLine();
        $this->line('<fg=blue>Videos sin terminar</>');

        if ($videos->isEmpty()) {
            $this->info('  No hay videos pendientes, en proceso ni con error.');

            return;
        }

        $this->table(
            ['ID', 'Archivo', 'Estado', 'Min. en estado', 'Original en disco', 'HLS en disco', 'Aviso / error'],
            $videos->map(fn (RecursoMultimedia $v): array => [
                $v->id,
                $v->nombre_original,
                $v->estado->etiqueta(),
                $procesamiento->minutosEnEstado($v),
                $this->existe($v->ruta_original) ? sprintf('sí (%s)', $v->ruta_original) : 'NO',
                $v->ruta_hls_manifiesto !== null && $this->existe($v->ruta_hls_manifiesto) ? 'sí' : 'no',
                match (true) {
                    $v->estado === EstadoMultimedia::Error => (string) $v->error_procesamiento,
                    $procesamiento->estaAtorado($v) => 'Atorado: el worker se detuvo a la mitad',
                    $procesamiento->pareceColaDetenida($v) => 'Nadie lo ha tomado: ¿corre el worker de video?',
                    default => '',
                },
            ])->all(),
        );
    }

    private function existe(string $ruta): bool
    {
        try {
            return $this->storage->existe($ruta);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  Collection<int, RecursoMultimedia>  $videos
     */
    private function procesar(ProcesamientoVideoService $procesamiento, Collection $videos): int
    {
        $incluirErrores = (bool) $this->option('incluir-errores');
        $candidatos = $videos->filter(fn (RecursoMultimedia $v): bool => $procesamiento->puedeReprocesarse($v)
            && ($v->estado !== EstadoMultimedia::Error || $incluirErrores));

        $this->newLine();

        if ($candidatos->isEmpty()) {
            $this->info('Nada que procesar.');

            return self::SUCCESS;
        }

        $fallas = 0;

        foreach ($candidatos as $video) {
            $this->line(sprintf('Procesando #%d «%s»…', $video->id, $video->nombre_original));

            try {
                $resultado = $procesamiento->procesarAhora($video);
            } catch (ValidationException $e) {
                $this->warn('  Omitido: '.collect($e->errors())->flatten()->implode(' '));

                continue;
            }

            if ($resultado->estado === EstadoMultimedia::Disponible) {
                $this->info('  Listo: ya se puede reproducir.');
            } else {
                $fallas++;
                $this->error(sprintf('  Quedó en «%s»: %s', $resultado->estado->etiqueta(), (string) $resultado->error_procesamiento));
            }
        }

        return $fallas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
