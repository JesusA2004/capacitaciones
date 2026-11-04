<?php

namespace App\Http\Resources\Api\V1\Integraciones;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sucursal para el acta de Auditoría (contrato «people-auditoria/2»): su
 * domicilio y su responsable designado. Lista blanca en dos niveles: sólo
 * salen las claves de CAMPOS, DOMICILIO y PERSONA aunque el servicio agregara
 * otras. Sin teléfono ni nada del expediente del responsable.
 *
 * @property array<string, mixed> $resource
 */
class SucursalActaAuditoriaResource extends JsonResource
{
    public const CAMPOS = ['id', 'empresa_id', 'clave', 'nombre', 'activo', 'domicilio', 'responsable_estado', 'responsable', 'updated_at'];

    public const DOMICILIO = ['direccion', 'colonia', 'municipio', 'ciudad', 'estado', 'codigo_postal'];

    /** Ficha de una persona relacionada (responsable o gerente). */
    public const PERSONA = ['id', 'numero_empleado', 'nombre_completo', 'puesto', 'empresa_id', 'sucursal', 'activo'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $datos = ListaBlancaAuditoria::filtrar($this->resource, self::CAMPOS);
        $datos['domicilio'] = ListaBlancaAuditoria::filtrar($datos['domicilio'] ?? [], self::DOMICILIO);
        $datos['responsable'] = ListaBlancaAuditoria::persona($datos['responsable'] ?? null);

        return $datos;
    }
}
