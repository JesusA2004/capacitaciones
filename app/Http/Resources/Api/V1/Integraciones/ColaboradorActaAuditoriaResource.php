<?php

namespace App\Http\Resources\Api\V1\Integraciones;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Colaborador para el acta de Auditoría (contrato «people-auditoria/2»):
 * fecha de nacimiento (la edad se calcula en Auditoría a la fecha del acta),
 * domicilio, si tiene clave de elector capturada (nunca el número) y su
 * gerente capturado. Nunca salario, CURP/RFC/NSS, teléfonos, correos, datos
 * médicos, contactos, credenciales, tokens ni archivos.
 *
 * @property array<string, mixed> $resource
 */
class ColaboradorActaAuditoriaResource extends JsonResource
{
    public const CAMPOS = [
        'id', 'numero_empleado', 'nombre_completo', 'puesto', 'empresa_id', 'sucursal', 'activo',
        'fecha_nacimiento', 'domicilio', 'identificacion', 'gerente_estado', 'gerente', 'updated_at',
    ];

    public const DOMICILIO = ['estructurado', 'calle', 'numero_exterior', 'numero_interior', 'colonia', 'municipio', 'estado', 'codigo_postal', 'texto'];

    public const IDENTIFICACION = ['clave_elector_registrada'];

    /**
     * Contrato «people-auditoria/3»: además, qué identificación oficial
     * presentó la persona y quién la expide. `clave_elector` (el número) sólo
     * trae valor si People tiene encendido `integraciones.auditoria.enviar_clave_elector`.
     */
    public const IDENTIFICACION_V3 = ['clave_elector_registrada', 'tipo', 'tipo_etiqueta', 'institucion', 'clave_elector'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $v3 = $request->attributes->get('contrato_auditoria') === 3;
        $datos = ListaBlancaAuditoria::filtrar($this->resource, self::CAMPOS);
        $datos['sucursal'] = is_array($datos['sucursal'] ?? null) ? ListaBlancaAuditoria::filtrar($datos['sucursal'], ['id', 'clave', 'nombre']) : null;
        $datos['domicilio'] = ListaBlancaAuditoria::filtrar($datos['domicilio'] ?? [], self::DOMICILIO);
        $datos['identificacion'] = ListaBlancaAuditoria::filtrar($datos['identificacion'] ?? [], $v3 ? self::IDENTIFICACION_V3 : self::IDENTIFICACION);
        $datos['gerente'] = ListaBlancaAuditoria::persona($datos['gerente'] ?? null);

        return $datos;
    }
}
