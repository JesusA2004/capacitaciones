<?php

namespace App\Jobs;

use App\Models\EmployeeDocument;
use App\Services\Documentos\DocumentExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispara la extraccion automatica de datos personales de un documento
 * recien subido (docs/DOCUMENT_EXTRACTION.md), en cola para que la subida
 * (App\Services\Expedientes\DocumentoStorageService::subirVersion) nunca
 * espere a que se lea/parsee el archivo. Cualquier fallo dentro de
 * DocumentExtractionService::procesar() ya se maneja ahi (deja la
 * extraccion en status=failed con error_message) — este job solo protege
 * contra un fallo *fuera* de ese try/catch (p. ej. el documento se borro
 * entre que se encolo y que se proceso).
 */
class ProcesarDocumentoPersonalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 15;

    public function __construct(public readonly EmployeeDocument $documento) {}

    public function handle(DocumentExtractionService $extraccion): void
    {
        $documento = $this->documento->fresh();

        if ($documento === null) {
            return;
        }

        try {
            $extraccion->procesar($documento);
        } catch (\Throwable $e) {
            Log::error('document_extraction: fallo inesperado en el job de extraccion.', [
                'employee_document_id' => $documento->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
