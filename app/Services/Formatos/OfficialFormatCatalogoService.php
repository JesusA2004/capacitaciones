<?php

namespace App\Services\Formatos;

use App\Models\OfficialFormat;

/**
 * Catálogo de formatos oficiales activos: usado por Rh\FormatoOficialController
 * (panel web) para la pantalla principal "/rh/formatos" — ver
 * docs/FORMATOS_OFICIALES.md.
 */
class OfficialFormatCatalogoService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(): array
    {
        return OfficialFormat::query()
            ->where('is_active', true)
            ->withCount('generaciones')
            ->with(['generaciones' => fn ($query) => $query->latest()->limit(1)])
            ->orderBy('nombre')
            ->get()
            ->map(fn (OfficialFormat $formato) => [
                'id' => $formato->id,
                'slug' => $formato->slug,
                'nombre' => $formato->nombre,
                'descripcion' => $formato->descripcion,
                'tipo' => $formato->tipo->value,
                'tipo_etiqueta' => $formato->tipo->etiqueta(),
                'file_type' => $formato->file_type,
                'lista' => $formato->tieneConfiguracion(),
                'veces_generado' => $formato->generaciones_count,
                'ultimo_generado_at' => $formato->generaciones->first()?->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
