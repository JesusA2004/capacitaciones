<?php

namespace App\Http\Resources\Api\V1\Integraciones;

/**
 * Filtro de lista blanca compartido por los recursos del acta de Auditoría:
 * cada clave que no está en la lista se descarta y cada clave de la lista
 * siempre aparece (null si falta), en el orden de la lista.
 */
final class ListaBlancaAuditoria
{
    /**
     * @param  array<string, mixed>  $origen
     * @param  list<string>  $campos
     * @return array<string, mixed>
     */
    public static function filtrar(array $origen, array $campos): array
    {
        $datos = [];

        foreach ($campos as $campo) {
            $datos[$campo] = $origen[$campo] ?? null;
        }

        return $datos;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function persona(mixed $persona): ?array
    {
        if (! is_array($persona)) {
            return null;
        }

        $datos = self::filtrar($persona, SucursalActaAuditoriaResource::PERSONA);
        $datos['sucursal'] = is_array($datos['sucursal']) ? self::filtrar($datos['sucursal'], ['id', 'clave', 'nombre']) : null;

        return $datos;
    }
}
