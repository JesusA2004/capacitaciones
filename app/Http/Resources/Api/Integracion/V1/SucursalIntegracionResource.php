<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;

/**
 * Sucursal con sus identificadores para emparejar con otros sistemas (id, clave, nombre normalizado).
 */
class SucursalIntegracionResource extends RecursoIntegracion
{
    protected function campos(Request $request): array
    {
        return ['id', 'clave', 'nombre', 'nombre_normalizado', 'empresa', 'ciudad', 'municipio', 'estado', 'es_corporativo', 'activo', 'eliminado', 'actualizado_en'];
    }
}
