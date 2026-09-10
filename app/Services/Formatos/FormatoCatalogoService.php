<?php

namespace App\Services\Formatos;

use App\Models\DocumentTemplate;
use App\Services\Plantillas\PlantillaDocumentoService;

/**
 * Catálogo de plantillas activas listas para generar un formato: usado por
 * el panel web (Rh\FormatoController) y por la API móvil de RH
 * (Api\V1\Rh\FormatoController) para no repetir la consulta ni el cálculo
 * de variables/último uso entre ambos.
 */
class FormatoCatalogoService
{
    public function __construct(
        private readonly PlantillaDocumentoService $generador,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(): array
    {
        return DocumentTemplate::query()
            ->where('activo', true)
            ->withCount('documentosGenerados')
            ->with(['documentosGenerados' => fn ($query) => $query->latest()->limit(1)])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'tipo', 'descripcion'])
            ->map(fn (DocumentTemplate $plantilla) => [
                'id' => $plantilla->id,
                'nombre' => $plantilla->nombre,
                'tipo' => $plantilla->tipo->value,
                'tipo_etiqueta' => $plantilla->tipo->etiqueta(),
                'descripcion' => $plantilla->descripcion,
                'variables' => $this->generador->variablesEnPlantilla($plantilla),
                'veces_generado' => $plantilla->documentos_generados_count,
                'ultimo_uso' => $plantilla->documentosGenerados->first()?->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
