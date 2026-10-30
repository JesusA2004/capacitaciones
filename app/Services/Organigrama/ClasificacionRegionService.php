<?php

namespace App\Services\Organigrama;

use App\Enums\TipoNodoComercial;
use App\Models\NodoComercial;

/**
 * Regiones Q1/Q3 de la matriz comercial: solo dato territorial. La
 * clasificación de gerentes se retiró; clasificaciones_region se conserva
 * como historial.
 */
class ClasificacionRegionService
{
    /**
     * Regiones de la matriz (Q1, Q3) para el selector y los chips.
     *
     * @return list<array{id: int, nombre: string, codigo: string}>
     */
    public function regiones(): array
    {
        return array_values(NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Region->value)
            ->where('activa', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'region'])
            ->map(fn (NodoComercial $region): array => [
                'id' => $region->id,
                'nombre' => $region->nombre,
                'codigo' => $region->region ?? trim(str_ireplace(['Región', 'Region'], '', $region->nombre)),
            ])
            ->all());
    }
}
