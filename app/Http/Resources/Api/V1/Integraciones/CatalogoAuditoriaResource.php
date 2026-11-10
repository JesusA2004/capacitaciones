<?php

namespace App\Http\Resources\Api\V1\Integraciones;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Puesto o departamento para el sistema Auditoría (contrato
 * «people-auditoria/3»): sólo identidad, nombre, vigencia y, en un puesto, su
 * departamento. Nunca descripción, tabulador, comisiones, requisitos ni
 * jerarquía.
 *
 * @property array<string, mixed> $resource
 */
class CatalogoAuditoriaResource extends JsonResource
{
    public const PUESTO = ['id', 'nombre', 'departamento', 'activo', 'updated_at'];

    public const DEPARTAMENTO = ['id', 'nombre', 'activo', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $esPuesto = array_key_exists('departamento', $this->resource);
        $datos = ListaBlancaAuditoria::filtrar($this->resource, $esPuesto ? self::PUESTO : self::DEPARTAMENTO);

        if ($esPuesto) {
            $datos['departamento'] = is_array($datos['departamento']) ? ListaBlancaAuditoria::filtrar($datos['departamento'], ['id', 'nombre']) : null;
        }

        return $datos;
    }
}
