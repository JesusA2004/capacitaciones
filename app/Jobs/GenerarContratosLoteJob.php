<?php

namespace App\Jobs;

use App\Models\LoteGeneracionContratos;
use App\Models\User;
use App\Services\Contratos\LoteContratosService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Generar todos» del módulo Contratos en la cola (requiere `queue:work`).
 * La pantalla consulta el progreso en lotes_generacion_contratos. Mismo
 * patrón que AplicarCargaInicialJob.
 */
class GenerarContratosLoteJob implements ShouldQueue
{
    use Queueable;

    /** Un PDF por documento × toda la plantilla activa: hasta 2 h. */
    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(
        public readonly int $loteId,
        public readonly int $actorId,
    ) {}

    public function handle(LoteContratosService $servicio): void
    {
        $lote = LoteGeneracionContratos::query()->where('id', $this->loteId)->first();
        $actor = User::query()->where('id', $this->actorId)->first();

        if ($lote === null) {
            return;
        }

        if ($actor === null) {
            $servicio->marcarFallido($this->loteId, 'La cuenta que inició la generación ya no existe.');

            return;
        }

        try {
            $servicio->procesar($lote, $actor);
        } catch (Throwable $e) {
            Log::error('Generación de contratos en lote falló.', ['lote_id' => $this->loteId, 'error' => $e->getMessage()]);
            $servicio->marcarFallido($this->loteId, $e->getMessage());
        }
    }

    /** Si la cola la corta (timeout), la pantalla no se queda «en proceso». */
    public function failed(?Throwable $e): void
    {
        app(LoteContratosService::class)->marcarFallido($this->loteId, $e?->getMessage() ?? 'La cola detuvo la generación de contratos.');
    }
}
