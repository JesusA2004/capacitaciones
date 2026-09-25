<?php

namespace App\Services\Formatos;

use App\Enums\EstadoVersionFormato;
use App\Models\OfficialFormat;

/**
 * Catálogo de plantillas oficiales (pantalla "Formatos" y selector de
 * "Generar documento" en el expediente / solicitud).
 */
class OfficialFormatCatalogoService
{
    /**
     * @param  array{tipo?: string|null, busqueda?: string|null, archivados?: bool, aplica_a?: string|null, solo_listos?: bool}  $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros = []): array
    {
        return array_values(OfficialFormat::query()
            ->with(['versionVigente:id,official_format_id,numero,estado,publicada_en,file_type,estrategia,fidelidad,campos', 'empresa:id,nombre'])
            ->withCount('generaciones')
            ->withMax('generaciones', 'created_at')
            ->withMax(['versiones as borrador_numero' => fn ($q) => $q->where('estado', EstadoVersionFormato::Borrador->value)], 'numero')
            ->when(($filtros['archivados'] ?? false) === true, fn ($q) => $q->whereNotNull('archivado_en'), fn ($q) => $q->whereNull('archivado_en'))
            ->when($filtros['tipo'] ?? null, fn ($q, string $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['aplica_a'] ?? null, fn ($q, string $aplica) => $q->where('aplica_a', $aplica))
            ->when($filtros['busqueda'] ?? null, fn ($q, string $texto) => $q->where('nombre', 'like', '%'.$texto.'%'))
            ->orderBy('nombre')
            ->get()
            ->when(($filtros['solo_listos'] ?? false) === true, fn ($lista) => $lista->filter(fn (OfficialFormat $f) => $f->tieneConfiguracion()))
            ->map(fn (OfficialFormat $formato) => $this->item($formato))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function item(OfficialFormat $formato): array
    {
        $vigente = $formato->versionVigente;
        $borrador = $formato->getAttribute('borrador_numero');
        $ultimo = $formato->getAttribute('generaciones_max_created_at');

        return [
            'id' => $formato->id,
            'slug' => $formato->slug,
            'nombre' => $formato->nombre,
            'descripcion' => $formato->descripcion,
            'tipo' => $formato->tipo->value,
            'tipo_etiqueta' => $formato->tipo->etiqueta(),
            'aplica_a' => $formato->aplica_a->value,
            'empresa' => $formato->empresa?->nombre,
            'file_type' => $vigente !== null ? $vigente->file_type->value : $formato->file_type,
            'lista' => $formato->tieneConfiguracion(),
            'archivado' => $formato->estaArchivado(),
            'version_vigente' => $vigente !== null ? [
                'id' => $vigente->id,
                'numero' => $vigente->numero,
                'publicada_en' => $vigente->publicada_en?->toIso8601String(),
                'fidelidad' => $vigente->fidelidad,
                'campos' => count($vigente->camposConfigurados()),
            ] : null,
            'borrador' => $borrador !== null ? (int) $borrador : null,
            'veces_generado' => (int) $formato->getAttribute('generaciones_count'),
            'ultimo_generado_at' => $ultimo !== null ? now()->parse((string) $ultimo)->toIso8601String() : null,
        ];
    }
}
