<?php

namespace App\Http\Resources\Api\V1\Integraciones;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Colaborador para el sistema Auditoría (contrato «people-auditoria/1»).
 * Segunda lista blanca: aunque el servicio agregara una clave nueva, sólo
 * salen las de CAMPOS. Nunca salario, contrato, documentos, expediente,
 * datos médicos, domicilio, CURP/RFC/NSS, teléfonos, correos, credenciales
 * ni fotos.
 *
 * @property array<string, mixed> $resource
 */
class ColaboradorAuditoriaResource extends JsonResource
{
    public const CAMPOS = [
        'id', 'numero_empleado', 'clave_noi', 'nombres', 'apellido_paterno', 'apellido_materno', 'apellidos',
        'nombre_completo', 'empresa', 'sucursal', 'puesto', 'estatus', 'activo', 'fecha_alta', 'fecha_baja', 'updated_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $datos = [];

        foreach (self::CAMPOS as $campo) {
            $datos[$campo] = $this->resource[$campo] ?? null;
        }

        return $datos;
    }
}
