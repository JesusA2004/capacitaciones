<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;

/**
 * Alta (ingreso) de un colaborador.
 */
class AltaIntegracionResource extends RecursoIntegracion
{
    protected function campos(Request $request): array
    {
        return ['tipo', 'colaborador_id', 'numero_empleado', 'nombre_completo', 'fecha', 'fecha_ingreso', 'fecha_baja', 'sucursal', 'puesto', 'departamento', 'tipo_contratacion', 'estatus'];
    }
}
