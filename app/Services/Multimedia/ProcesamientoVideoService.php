<?php

namespace App\Services\Multimedia;

use App\Enums\EstadoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Jobs\ProcesarVideoJob;
use App\Models\RecursoMultimedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Estado y recuperación del procesamiento de video (ProcesarVideoJob) fuera
 * del job mismo: qué tan viejo es un «pendiente»/«procesando», si parece que
 * la cola no está corriendo, y cómo volver a lanzarlo (en cola o en línea)
 * sin volver a subir el archivo. Lo usan la pantalla de RH de la inducción
 * (MaterialInduccionService) y el comando `people:videos-pendientes`.
 */
class ProcesamientoVideoService
{
    /** Minutos que el recurso lleva en su estado actual (desde su último cambio). */
    public function minutosEnEstado(RecursoMultimedia $recurso): int
    {
        $desde = $recurso->updated_at ?? $recurso->created_at;

        return $desde !== null ? (int) $desde->diffInMinutes(now()) : 0;
    }

    /**
     * true cuando un video lleva demasiado sin avanzar: «pendiente» más de
     * config('media.cola.alerta_minutos') (nadie tomó el trabajo: la cola de
     * video no está corriendo) o «procesando» más de lo que puede durar el job.
     */
    public function pareceColaDetenida(RecursoMultimedia $recurso): bool
    {
        return match ($recurso->estado) {
            EstadoMultimedia::Pendiente => $this->minutosEnEstado($recurso) >= (int) config('media.cola.alerta_minutos'),
            EstadoMultimedia::Procesando => $this->estaAtorado($recurso),
            default => false,
        };
    }

    /** «procesando» por más tiempo del que puede durar el job (worker caído a la mitad). */
    public function estaAtorado(RecursoMultimedia $recurso): bool
    {
        return $recurso->estado === EstadoMultimedia::Procesando
            && $this->minutosEnEstado($recurso) >= (int) config('media.cola.procesando_atorado_minutos');
    }

    /** Se puede volver a lanzar: en error, pendiente o atorado (nunca uno disponible o en curso). */
    public function puedeReprocesarse(RecursoMultimedia $recurso): bool
    {
        return $recurso->tipo === TipoRecursoMultimedia::Video
            && ($recurso->estado === EstadoMultimedia::Error
                || $recurso->estado === EstadoMultimedia::Pendiente
                || $this->estaAtorado($recurso));
    }

    /**
     * Vuelve a encolar la conversión (tras instalar FFmpeg, levantar el worker,
     * etc.), sin volver a subir el archivo original.
     *
     * @throws ValidationException si el recurso no admite reproceso
     */
    public function reencolar(RecursoMultimedia $recurso): void
    {
        $this->prepararReproceso($recurso);
        ProcesarVideoJob::dispatch($recurso->refresh());
    }

    /**
     * Procesa el video EN ESTE PROCESO (dispatchSync), sin pasar por la cola:
     * para recuperar desde la consola un video atorado. Idempotente: el job no
     * repite nada si el recurso ya quedó disponible.
     *
     * @throws ValidationException si el recurso no admite reproceso
     */
    public function procesarAhora(RecursoMultimedia $recurso): RecursoMultimedia
    {
        $this->prepararReproceso($recurso);
        ProcesarVideoJob::dispatchSync($recurso->refresh());

        return $recurso->refresh();
    }

    /**
     * Videos que todavía no se pueden reproducir (pendiente / procesando /
     * error), del más viejo al más nuevo.
     *
     * @return Collection<int, RecursoMultimedia>
     */
    public function videosSinTerminar(): Collection
    {
        return RecursoMultimedia::query()
            ->where('tipo', TipoRecursoMultimedia::Video->value)
            ->whereIn('estado', [EstadoMultimedia::Pendiente->value, EstadoMultimedia::Procesando->value, EstadoMultimedia::Error->value])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Trabajos esperando en la tabla `jobs` (conexiones database / media /
     * notificaciones comparten la tabla), agrupados por cola, con cuántos son
     * de video y la antigüedad del más viejo. Vacío si la tabla no existe.
     *
     * @return list<array{cola: string, total: int, videos: int, mas_antiguo_minutos: int}>
     */
    public function trabajosEnTablaJobs(): array
    {
        $tabla = (string) config('queue.connections.database.table', 'jobs');

        if (! Schema::hasTable($tabla)) {
            return [];
        }

        return array_values(DB::table($tabla)
            ->selectRaw('queue, COUNT(*) as total, SUM(CASE WHEN payload LIKE ? THEN 1 ELSE 0 END) as videos, MIN(created_at) as mas_antiguo', ['%ProcesarVideoJob%'])
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn (object $fila): array => [
                'cola' => sprintf('%s', $fila->queue),
                'total' => (int) $fila->total,
                'videos' => (int) $fila->videos,
                'mas_antiguo_minutos' => (int) max(0, intdiv(now()->getTimestamp() - (int) $fila->mas_antiguo, 60)),
            ])
            ->all());
    }

    /**
     * @throws ValidationException
     */
    private function prepararReproceso(RecursoMultimedia $recurso): void
    {
        if (! $this->puedeReprocesarse($recurso)) {
            throw ValidationException::withMessages([
                'recurso' => $recurso->estado === EstadoMultimedia::Disponible
                    ? 'El video ya está listo; no hace falta volver a procesarlo.'
                    : 'El video se está procesando en este momento; espera a que termine.',
            ]);
        }

        $recurso->update([
            'estado' => EstadoMultimedia::Pendiente->value,
            'error_procesamiento' => null,
        ]);
    }
}
