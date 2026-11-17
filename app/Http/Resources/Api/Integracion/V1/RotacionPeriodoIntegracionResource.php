<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;

/**
 * Un periodo de rotación: totales y, si se pidió, el desglose por sucursal.
 */
class RotacionPeriodoIntegracionResource extends RecursoIntegracion
{
    protected function campos(Request $request): array
    {
        return ['clave', 'etiqueta', 'inicio', 'fin', 'corte', 'completo', 'corte_parcial', 'totales', 'sucursales'];
    }
}
