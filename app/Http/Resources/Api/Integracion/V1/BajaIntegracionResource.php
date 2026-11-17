<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;

/**
 * Baja (evento de rotación del motor único). Sin motivo en texto libre: sólo catálogos cerrados.
 */
class BajaIntegracionResource extends RecursoIntegracion
{
    protected function campos(Request $request): array
    {
        return ['tipo', 'clave', 'fuente', 'colaborador_id', 'numero_empleado', 'nombre_completo', 'fecha', 'fecha_ingreso', 'fecha_baja', 'sucursal', 'puesto_id', 'departamento_id', 'forma_salida', 'clasificacion', 'causal', 'antiguedad_dias', 'rango_antiguedad'];
    }
}
