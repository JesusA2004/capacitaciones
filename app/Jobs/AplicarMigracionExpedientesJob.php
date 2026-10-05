<?php

namespace App\Jobs;

use App\Models\MigracionExpedientes;
use App\Models\User;
use App\Services\Expedientes\MigracionInicial\ExpedientesInitialMigrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ejecuta la migración inicial en la cola (requiere `queue:work`, ver
 * docs/DEPLOY.md), así el request nunca se queda atorado. La pantalla
 * consulta el avance (etapa y progreso) en migraciones_expedientes.
 * Para la cola `database`, DB_QUEUE_RETRY_AFTER debe ser mayor que $timeout
 * (si no, el worker la reintentaría mientras sigue corriendo; el lock y la
 * idempotencia lo protegen, pero se registraría un fallo falso).
 */
class AplicarMigracionExpedientesJob implements ShouldQueue
{
    use Queueable;

    /** Una migración real puede tardar: hasta 2 h. */
    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(
        public readonly int $migracionId,
        public readonly int $actorId,
        public readonly string $modo,
    ) {}

    public function handle(ExpedientesInitialMigrationService $servicio): void
    {
        $migracion = MigracionExpedientes::query()->find($this->migracionId);
        $actor = User::query()->where('id', $this->actorId)->first();

        if ($migracion === null || $actor === null) {
            return;
        }

        try {
            $servicio->aplicar($migracion, $actor, $this->modo);
        } catch (Throwable $e) {
            Log::error('Migración inicial de expedientes falló.', ['migracion_id' => $this->migracionId, 'error' => $e->getMessage()]);
        }
    }

    /** Si la cola la corta (timeout), la pantalla no se queda en «en proceso». */
    public function failed(?Throwable $e): void
    {
        MigracionExpedientes::query()->whereKey($this->migracionId)->whereIn('estado', ['en_cola', 'aplicando'])
            ->update(['estado' => 'fallido', 'error' => $e?->getMessage() ?? 'La cola detuvo la migración.', 'terminada_en' => now()]);
    }
}
