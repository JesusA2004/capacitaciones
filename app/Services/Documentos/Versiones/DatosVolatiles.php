<?php

namespace App\Services\Documentos\Versiones;

/**
 * Datos que cambian solos (fecha de emisión, referencias, sellos): no cuentan
 * para decidir si un documento cambió. Si RH los corrigió, sí cuentan.
 */
final class DatosVolatiles
{
    private const PATRON = '/^(lugar_fecha|fecha_documento.*|fecha_actual.*|fecha_hoy.*|fecha_emision.*|fecha_acta_larga|referencia_documento|generado_en|folio_impresion)$/';

    public static function esVolatil(string $clave): bool
    {
        return preg_match(self::PATRON, $clave) === 1;
    }

    /**
     * @param  array<string, mixed>  $valores
     * @param  array<string, string>  $correcciones
     * @return array<string, mixed>
     */
    public static function quitar(array $valores, array $correcciones = []): array
    {
        return array_filter($valores, fn (string $clave): bool => ! self::esVolatil($clave) || array_key_exists($clave, $correcciones), ARRAY_FILTER_USE_KEY);
    }
}
