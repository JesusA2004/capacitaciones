<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;

/**
 * Empresa (catálogo).
 */
class EmpresaIntegracionResource extends RecursoIntegracion
{
    protected function campos(Request $request): array
    {
        return ['id', 'nombre', 'razon_social', 'activo', 'actualizado_en'];
    }
}
