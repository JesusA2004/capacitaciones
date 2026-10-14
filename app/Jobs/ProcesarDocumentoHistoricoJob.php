<?php

namespace App\Jobs;

use App\Models\ExpedienteHistorico;
use App\Services\Documentos\DocumentExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Igual que ProcesarDocumentoPersonalJob pero para un ExpedienteHistorico
 * (PDF único de la migración inicial) — usado por `expedientes:extraer-existentes
 * --enqueue` para no bloquear el comando procesando cada histórico en la
 * misma ejecución.
 */
class ProcesarDocumentoHistoricoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $backoff = 15;

    public function __construct(public readonly ExpedienteHistorico $historico) {}

    public function handle(DocumentExtractionService $extraccion): void
    {
        $historico = $this->historico->fresh();

        if ($historico === null || ! DocumentExtractionService::historicoElegible($historico)) {
            return;
        }

        try {
            $extraccion->procesarHistorico($historico);
        } catch (\Throwable $e) {
            Log::error('document_extraction: fallo inesperado en el job de extraccion de historico.', [
                'expediente_historico_id' => $historico->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
