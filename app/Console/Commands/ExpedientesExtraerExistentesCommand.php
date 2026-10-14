<?php

namespace App\Console\Commands;

use App\Jobs\ProcesarDocumentoPersonalJob;
use App\Models\EmployeeDocument;
use App\Services\Documentos\DocumentExtractionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Corre la extracción automática de datos personales (CURP, RFC, NSS, fecha
 * de nacimiento y los campos con etiqueta explícita de
 * RegexPersonalDataExtractor) sobre documentos de expediente YA EXISTENTES
 * en el NAS — para no volver a pedirle al colaborador un dato que RH ya
 * tiene en su expediente (encargo de cierre, sección 29-43).
 *
 * SIN --ejecutar es un reporte (no lee ningún archivo del NAS, no escribe
 * nada): solo cuenta cuántos documentos entrarían. Con --ejecutar sí
 * procesa — y aun así NUNCA modifica al colaborador: solo crea/actualiza
 * sugerencias en document_extractions, que RH aplica a mano desde el
 * expediente (DocumentExtraccionController::aplicar).
 */
class ExpedientesExtraerExistentesCommand extends Command
{
    protected $signature = 'expedientes:extraer-existentes
        {--ejecutar : Sin esta opción solo se reporta cuántos documentos entrarían (no lee el NAS ni escribe nada)}
        {--enqueue : Encola cada documento (ProcesarDocumentoPersonalJob) en vez de procesarlo en la misma ejecución}
        {--colaborador= : Limita a un colaborador por id}
        {--solo-faltantes : Solo documentos de colaboradores a los que aún les falta CURP, RFC, NSS o fecha de nacimiento}
        {--forzar : Vuelve a procesar aunque ya exista una extracción vigente con el mismo hash y versión del extractor}';

    protected $description = 'Procesa documentos de expediente YA EXISTENTES en el NAS para sugerir datos personales faltantes, sin modificar al colaborador';

    public function handle(DocumentExtractionService $extraccion): int
    {
        $ejecutar = (bool) $this->option('ejecutar');
        $enqueue = (bool) $this->option('enqueue');
        $forzar = (bool) $this->option('forzar');
        $colaboradorId = $this->option('colaborador');

        $documentos = $this->consulta($colaboradorId, (bool) $this->option('solo-faltantes'))->get();

        if ($documentos->isEmpty()) {
            $this->info('No hay documentos elegibles con los filtros indicados.');

            return self::SUCCESS;
        }

        $this->line(sprintf('<fg=blue>%d documento(s) elegible(s) para extracción.</>', $documentos->count()));

        $vigentes = 0;
        $pendientes = $documentos->reject(function (EmployeeDocument $documento) use ($extraccion, $forzar, &$vigentes) {
            $yaVigente = ! $forzar && $extraccion->tieneExtraccionVigente($documento);
            if ($yaVigente) {
                $vigentes++;
            }

            return $yaVigente;
        });

        $this->line(sprintf('  %d ya tienen una extracción vigente (mismo archivo, misma versión) — se omiten.', $vigentes));
        $this->line(sprintf('  %d se %s.', $pendientes->count(), $ejecutar ? 'van a procesar' : 'procesarían con --ejecutar'));

        if (! $ejecutar) {
            $this->newLine();
            $this->warn('Reporte únicamente (DRY RUN): no se leyó ningún archivo del NAS ni se escribió nada. Repite con --ejecutar para procesar.');

            return self::SUCCESS;
        }

        if ($pendientes->isEmpty()) {
            return self::SUCCESS;
        }

        $resumen = ['procesados' => 0, 'con_datos' => 0, 'ocr' => 0, 'conflictos' => 0, 'sin_datos' => 0, 'errores' => 0, 'encolados' => 0];

        $this->withProgressBar($pendientes, function (EmployeeDocument $documento) use ($extraccion, $enqueue, &$resumen) {
            if ($enqueue) {
                ProcesarDocumentoPersonalJob::dispatch($documento);
                $resumen['encolados']++;

                return;
            }

            $resultado = $extraccion->procesar($documento);
            $resumen['procesados']++;

            if ($resultado->status->value === 'failed') {
                $resumen['errores']++;

                return;
            }

            if ($resultado->ocr_used) {
                $resumen['ocr']++;
            }

            $datos = $resultado->extracted_data ?? [];
            if ($datos !== []) {
                $resumen['con_datos']++;
            } else {
                $resumen['sin_datos']++;
            }

            $tieneConflicto = collect($resultado->differences ?? [])->contains(fn (array $d): bool => $d['actual'] !== null && ! $d['coincide']);
            if ($tieneConflicto) {
                $resumen['conflictos']++;
            }
        });

        $this->newLine(2);

        if ($enqueue) {
            $this->info("Encolados: {$resumen['encolados']} documento(s) (ProcesarDocumentoPersonalJob, cola por defecto).");

            return self::SUCCESS;
        }

        $this->line('<fg=blue>Resumen</>');
        $this->line("  Procesados: {$resumen['procesados']}");
        $this->line("  Con datos detectados: {$resumen['con_datos']}");
        $this->line("  Vía OCR local: {$resumen['ocr']}");
        $this->line("  Con conflicto contra el dato actual del colaborador: {$resumen['conflictos']}");
        $this->line("  Sin datos detectados: {$resumen['sin_datos']}");
        $this->line("  Con error (NAS/PDF/imagen ilegible): {$resumen['errores']}");
        $this->newLine();
        $this->info('RH revisa y aplica cada sugerencia desde el expediente del colaborador — nada se aplicó automáticamente.');

        return self::SUCCESS;
    }

    /**
     * @return Builder<EmployeeDocument>
     */
    private function consulta(?string $colaboradorId, bool $soloFaltantes): Builder
    {
        $query = EmployeeDocument::query()
            ->whereHas('tipo', fn ($q) => $q->whereIn('clave', DocumentExtractionService::TIPOS_ELEGIBLES))
            ->whereNotNull('colaborador_id')
            ->with(['tipo', 'colaborador', 'extraccion'])
            ->orderBy('id');

        if ($colaboradorId !== null) {
            $query->where('colaborador_id', $colaboradorId);
        }

        if ($soloFaltantes) {
            $query->whereHas('colaborador', function ($q) {
                $q->where(function ($q2) {
                    foreach (DocumentExtractionService::CAMPOS_APLICABLES as $campo) {
                        $q2->orWhereNull($campo);
                    }
                });
            });
        }

        return $query;
    }
}
