<?php

namespace Tests\Support;

use App\Enums\EstadoValidacionVisual;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\DocumentosMaestros\Calidad\ValidacionVisualMaestroService;

/**
 * QA visual de PRUEBA: marca la versión como validada con Word sin
 * rasterizar nada (la comparación real se prueba con ComparadorVisual y en
 * el grupo "fidelidad").
 */
class ValidacionVisualDePrueba extends ValidacionVisualMaestroService
{
    public ?int $paginasOriginal = null;

    public function __construct() {}

    public function impedimento(DocumentTemplate $master): ?string
    {
        return null;
    }

    public function validar(DocumentTemplate $master, ?User $actor = null): DocumentTemplate
    {
        $master->forceFill([
            'visual_validation_status' => EstadoValidacionVisual::Aprobada,
            'visual_similarity' => 1.0,
            'page_count_original' => $this->paginasOriginal,
            'page_count_output' => $this->paginasOriginal,
            'visual_checked_at' => now(),
            'visual_engine' => $master->motor->value === 'pdf_overlay' ? 'overlay' : 'word',
            'visual_report' => ['problemas' => [], 'advertencias' => [], 'desbordes' => [], 'fidelidad' => 'nativa', 'motor' => 'word', 'prueba' => true],
        ])->save();

        return $master->refresh();
    }
}
