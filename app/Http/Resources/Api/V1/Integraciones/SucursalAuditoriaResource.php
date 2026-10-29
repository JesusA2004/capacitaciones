<?php

namespace App\Http\Resources\Api\V1\Integraciones;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sucursal para el sistema Auditoría: sin domicilio, teléfono ni responsable.
 *
 * @property array<string, mixed> $resource
 */
class SucursalAuditoriaResource extends JsonResource
{
    public const CAMPOS = ['id', 'empresa_id', 'clave', 'nombre', 'activo', 'updated_at'];

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
