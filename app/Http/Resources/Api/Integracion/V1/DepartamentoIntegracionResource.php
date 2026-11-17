<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;

/**
 * Departamento (catálogo).
 */
class DepartamentoIntegracionResource extends RecursoIntegracion
{
    protected function campos(Request $request): array
    {
        return ['id', 'nombre', 'activo', 'eliminado', 'actualizado_en'];
    }
}
