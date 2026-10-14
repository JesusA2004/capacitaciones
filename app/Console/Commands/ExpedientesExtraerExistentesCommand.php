<?php

namespace App\Console\Commands;

use App\Jobs\ProcesarDocumentoHistoricoJob;
use App\Jobs\ProcesarDocumentoPersonalJob;
use App\Models\DocumentExtraction;
use App\Models\EmployeeDocument;
use App\Models\ExpedienteHistorico;
use App\Services\Documentos\DocumentExtractionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Corre la extracción automática de datos personales sobre documentos de
 * expediente YA EXISTENTES en el NAS — para no volver a pedirle al
 * colaborador un dato que RH ya tiene. Dos fuentes:
 *
 *  - EmployeeDocument: documentos normales del checklist de expediente
 *    (INE, CURP, RFC, NSS, acta de nacimiento, comprobante de domicilio).
 *  - ExpedienteHistorico: el PDF único de la migración inicial, SOLO cuando
 *    ya está vinculado a un colaborador (los pendientes de vincular no se
 *    tocan aquí) — nunca se mueve/renombra/corta/borra, solo se lee.
 *
 * SIN --ejecutar es un reporte (no lee ningún archivo del NAS, no escribe
 * nada): solo cuenta cuántos entrarían. Con --ejecutar sí procesa — y aun
 * así NUNCA modifica al colaborador: solo crea/actualiza sugerencias en
 * document_extractions, que RH aplica a mano desde el expediente
 * (DocumentExtraccionController::aplicar).
 *
 * Usa chunkById() en vez de get(): esto debe poder procesar cientos/miles de
 * archivos sin cargarlos todos en memoria de una vez.
 */
class ExpedientesExtraerExistentesCommand extends Command
{
    private const TAMANO_CHUNK = 200;

    protected $signature = 'expedientes:extraer-existentes
        {--ejecutar : Sin esta opción solo se reporta cuántos documentos entrarían (no lee el NAS ni escribe nada)}
        {--enqueue : Encola cada documento/histórico (ProcesarDocumentoPersonalJob/ProcesarDocumentoHistoricoJob) en vez de procesarlo en la misma ejecución}
        {--colaborador= : Limita a un colaborador por id}
        {--solo-faltantes : Solo colaboradores a los que aún les falta algún campo aprovechable}
        {--forzar : Vuelve a procesar aunque ya exista una extracción vigente con el mismo hash y versión del extractor}';

    protected $description = 'Procesa documentos y expedientes históricos YA EXISTENTES en el NAS para sugerir datos personales faltantes, sin modificar al colaborador';

    /** @var array<string, int> */
    private array $resumen = [
        'vigentes' => 0, 'procesados' => 0, 'con_datos' => 0, 'ocr' => 0,
        'conflictos' => 0, 'sin_datos' => 0, 'errores' => 0, 'encolados' => 0,
    ];

    public function handle(DocumentExtractionService $extraccion): int
    {
        $ejecutar = (bool) $this->option('ejecutar');
        $enqueue = (bool) $this->option('enqueue');
        $forzar = (bool) $this->option('forzar');
        $colaboradorId = $this->option('colaborador');
        $soloFaltantes = (bool) $this->option('solo-faltantes');

        $totalDocumentos = (clone $this->consultaDocumentos($colaboradorId, $soloFaltantes))->count();
        $totalHistoricos = (clone $this->consultaHistoricos($colaboradorId, $soloFaltantes))->count();

        if ($totalDocumentos === 0 && $totalHistoricos === 0) {
            $this->info('No hay documentos ni expedientes históricos elegibles con los filtros indicados.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<fg=blue>%d documento(s) de expediente y %d expediente(s) histórico(s) elegible(s).</>',
            $totalDocumentos,
            $totalHistoricos,
        ));

        if (! $ejecutar) {
            $this->reportarDryRun($extraccion, $colaboradorId, $soloFaltantes, $forzar, $totalDocumentos, $totalHistoricos);

            return self::SUCCESS;
        }

        $barra = $this->output->createProgressBar($totalDocumentos + $totalHistoricos);
        $barra->start();

        $this->consultaDocumentos($colaboradorId, $soloFaltantes)->chunkById(self::TAMANO_CHUNK, function ($documentos) use ($extraccion, $enqueue, $forzar, $barra) {
            foreach ($documentos as $documento) {
                $this->procesarDocumento($extraccion, $documento, $enqueue, $forzar);
                $barra->advance();
            }
        });

        $this->consultaHistoricos($colaboradorId, $soloFaltantes)->chunkById(self::TAMANO_CHUNK, function ($historicos) use ($extraccion, $enqueue, $forzar, $barra) {
            foreach ($historicos as $historico) {
                $this->procesarHistorico($extraccion, $historico, $enqueue, $forzar);
                $barra->advance();
            }
        });

        $barra->finish();
        $this->newLine(2);

        if ($enqueue) {
            $this->info("Encolados: {$this->resumen['encolados']} (ProcesarDocumentoPersonalJob/ProcesarDocumentoHistoricoJob, cola por defecto).");
            $this->line("  {$this->resumen['vigentes']} ya tenían una extracción vigente — se omitieron.");

            return self::SUCCESS;
        }

        $this->line('<fg=blue>Resumen</>');
        $this->line("  Ya vigentes (se omitieron): {$this->resumen['vigentes']}");
        $this->line("  Procesados: {$this->resumen['procesados']}");
        $this->line("  Con datos detectados: {$this->resumen['con_datos']}");
        $this->line("  Vía OCR local: {$this->resumen['ocr']}");
        $this->line("  Con conflicto contra el dato actual del colaborador: {$this->resumen['conflictos']}");
        $this->line("  Sin datos detectados: {$this->resumen['sin_datos']}");
        $this->line("  Con error (NAS/PDF/imagen ilegible): {$this->resumen['errores']}");
        $this->newLine();
        $this->info('RH revisa y aplica cada sugerencia desde el expediente del colaborador — nada se aplicó automáticamente.');

        return self::SUCCESS;
    }

    private function procesarDocumento(DocumentExtractionService $extraccion, EmployeeDocument $documento, bool $enqueue, bool $forzar): void
    {
        if (! $forzar && $extraccion->tieneExtraccionVigente($documento)) {
            $this->resumen['vigentes']++;

            return;
        }

        if ($enqueue) {
            ProcesarDocumentoPersonalJob::dispatch($documento);
            $this->resumen['encolados']++;

            return;
        }

        $this->acumular($extraccion->procesar($documento));
    }

    private function procesarHistorico(DocumentExtractionService $extraccion, ExpedienteHistorico $historico, bool $enqueue, bool $forzar): void
    {
        if (! $forzar && $extraccion->tieneExtraccionVigenteHistorico($historico)) {
            $this->resumen['vigentes']++;

            return;
        }

        if ($enqueue) {
            ProcesarDocumentoHistoricoJob::dispatch($historico);
            $this->resumen['encolados']++;

            return;
        }

        $this->acumular($extraccion->procesarHistorico($historico));
    }

    private function acumular(DocumentExtraction $resultado): void
    {
        $this->resumen['procesados']++;

        if ($resultado->status->value === 'failed') {
            $this->resumen['errores']++;

            return;
        }

        if ($resultado->ocr_used) {
            $this->resumen['ocr']++;
        }

        $datos = $resultado->extracted_data ?? [];
        if ($datos !== []) {
            $this->resumen['con_datos']++;
        } else {
            $this->resumen['sin_datos']++;
        }

        $tieneConflicto = collect($resultado->differences ?? [])->contains(fn (array $d): bool => $d['actual'] !== null && ! $d['coincide']);
        if ($tieneConflicto) {
            $this->resumen['conflictos']++;
        }
    }

    private function reportarDryRun(DocumentExtractionService $extraccion, ?string $colaboradorId, bool $soloFaltantes, bool $forzar, int $totalDocumentos, int $totalHistoricos): void
    {
        $vigentes = 0;

        $this->consultaDocumentos($colaboradorId, $soloFaltantes)->chunkById(self::TAMANO_CHUNK, function ($documentos) use ($extraccion, $forzar, &$vigentes) {
            foreach ($documentos as $documento) {
                if (! $forzar && $extraccion->tieneExtraccionVigente($documento)) {
                    $vigentes++;
                }
            }
        });

        $this->consultaHistoricos($colaboradorId, $soloFaltantes)->chunkById(self::TAMANO_CHUNK, function ($historicos) use ($extraccion, $forzar, &$vigentes) {
            foreach ($historicos as $historico) {
                if (! $forzar && $extraccion->tieneExtraccionVigenteHistorico($historico)) {
                    $vigentes++;
                }
            }
        });

        $pendientes = ($totalDocumentos + $totalHistoricos) - $vigentes;

        $this->line(sprintf('  %d ya tienen una extracción vigente (mismo archivo, misma versión) — se omiten.', $vigentes));
        $this->line(sprintf('  %d se procesarían con --ejecutar.', $pendientes));
        $this->newLine();
        $this->warn('Reporte únicamente (DRY RUN): no se leyó ningún archivo del NAS ni se escribió nada. Repite con --ejecutar para procesar.');
    }

    /**
     * @return Builder<EmployeeDocument>
     */
    private function consultaDocumentos(?string $colaboradorId, bool $soloFaltantes): Builder
    {
        $query = EmployeeDocument::query()
            ->whereHas('tipo', fn ($q) => $q->whereIn('clave', DocumentExtractionService::TIPOS_ELEGIBLES))
            ->whereNotNull('colaborador_id')
            ->with(['tipo', 'colaborador', 'extraccion']);

        if ($colaboradorId !== null) {
            $query->where('colaborador_id', $colaboradorId);
        }

        if ($soloFaltantes) {
            $query->whereHas('colaborador', function ($q) {
                $q->where(function ($q2) {
                    foreach (DocumentExtractionService::columnasColaborador() as $columna) {
                        $q2->orWhereNull($columna);
                    }
                });
            });
        }

        return $query;
    }

    /**
     * @return Builder<ExpedienteHistorico>
     */
    private function consultaHistoricos(?string $colaboradorId, bool $soloFaltantes): Builder
    {
        $query = ExpedienteHistorico::query()
            ->where('estado', ExpedienteHistorico::VINCULADO)
            ->whereNotNull('colaborador_id')
            ->with(['colaborador', 'extraccion']);

        if ($colaboradorId !== null) {
            $query->where('colaborador_id', $colaboradorId);
        }

        if ($soloFaltantes) {
            $query->whereHas('colaborador', function ($q) {
                $q->where(function ($q2) {
                    foreach (DocumentExtractionService::columnasColaborador() as $columna) {
                        $q2->orWhereNull($columna);
                    }
                });
            });
        }

        return $query;
    }
}
