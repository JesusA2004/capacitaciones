<?php

namespace App\Jobs;

use App\Models\ExpedienteHistorico;
use App\Models\User;
use App\Services\Expedientes\DivisionHistorico\DivisionExpedienteHistoricoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Divide en cola el PDF histórico por checklist (la lectura con OCR de un
 * PDF largo tarda minutos; nunca bloquea el request de RH).
 * docs/DIVISION_EXPEDIENTE_HISTORICO.md.
 */
class DividirExpedienteHistoricoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly ExpedienteHistorico $historico,
        public readonly User $usuario,
        public readonly bool $forzar = false,
    ) {}

    public function handle(DivisionExpedienteHistoricoService $division): void
    {
        $historico = $this->historico->fresh();

        if ($historico === null || ! $division->elegible($historico)) {
            return;
        }

        try {
            $division->dividir($historico, $this->usuario, $this->forzar);
        } catch (Throwable $e) {
            Log::error('division_historico: fallo inesperado en el job.', ['expediente_historico_id' => $historico->id, 'error' => $e->getMessage()]);
        }
    }
}
