<?php

namespace App\Jobs;

use App\Models\MigracionExpedientes;
use App\Models\User;
use App\Services\CargaInicial\CargaInicialPeopleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aplica la «Carga inicial PEOPLE» en la cola (requiere `queue:work`, ver
 * docs/DEPLOY.md). La pantalla consulta etapa y progreso en
 * migraciones_expedientes. Mismo patrón que AplicarMigracionExpedientesJob.
 */
class AplicarCargaInicialJob implements ShouldQueue
{
    use Queueable;

    /** Colaboradores + NAS + catálogos + headcount + rotación: hasta 2 h. */
    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(
        public readonly int $migracionId,
        public readonly int $actorId,
    ) {}

    public function handle(CargaInicialPeopleService $servicio): void
    {
        $migracion = MigracionExpedientes::query()->where('id', $this->migracionId)->first();
        $actor = User::query()->where('id', $this->actorId)->first();

        if ($migracion === null || $actor === null) {
            return;
        }

        try {
            $servicio->aplicar($migracion, $actor);
        } catch (Throwable $e) {
            Log::error('Carga inicial PEOPLE falló.', ['migracion_id' => $this->migracionId, 'error' => $e->getMessage()]);
        }
    }

    /** Si la cola la corta (timeout), la pantalla no se queda «en proceso». */
    public function failed(?Throwable $e): void
    {
        MigracionExpedientes::query()->whereKey($this->migracionId)->whereIn('estado', ['en_cola', 'aplicando'])
            ->update(['estado' => 'fallido', 'error' => $e?->getMessage() ?? 'La cola detuvo la carga inicial.', 'terminada_en' => now()]);
    }
}
