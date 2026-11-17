<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;

/**
 * Puesto con su departamento.
 */
class PuestoIntegracionResource extends RecursoIntegracion
{
    protected function campos(Request $request): array
    {
        return ['id', 'nombre', 'departamento', 'nivel_jerarquico', 'tipo', 'activo', 'eliminado', 'actualizado_en'];
    }
}
