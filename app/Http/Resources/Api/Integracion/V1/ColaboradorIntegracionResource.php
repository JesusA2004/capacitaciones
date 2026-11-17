<?php

namespace App\Http\Resources\Api\Integracion\V1;

use App\Services\Integraciones\Sistemas\PlantillaIntegracionService;
use Illuminate\Http\Request;

/**
 * Colaborador de la plantilla. `identificacion` (CURP, RFC, NSS) y `salario`
 * sólo salen si el controlador los autorizó para esta petición (habilidad
 * separada + `incluir=`): lo marca en el atributo ATRIBUTO_INCLUIR.
 */
class ColaboradorIntegracionResource extends RecursoIntegracion
{
    public const ATRIBUTO_INCLUIR = 'integracion_incluir';

    protected function campos(Request $request): array
    {
        $campos = [
            'id', 'numero_empleado', 'clave_noi', 'nombres', 'apellidos', 'nombre_completo', 'estatus', 'activo',
            'fecha_ingreso', 'fecha_baja', 'tipo_contratacion', 'empresa', 'sucursal', 'puesto', 'departamento', 'actualizado_en',
        ];
        $incluir = $request->attributes->get(self::ATRIBUTO_INCLUIR);
        $incluir = is_array($incluir) ? $incluir : [];

        if (in_array(PlantillaIntegracionService::INCLUIR_IDENTIFICACION, $incluir, true)) {
            $campos[] = 'identificacion';
        }

        if (in_array(PlantillaIntegracionService::INCLUIR_SALARIO, $incluir, true)) {
            $campos[] = 'salario';
        }

        return $campos;
    }
}
