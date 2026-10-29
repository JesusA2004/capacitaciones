<?php

namespace App\Services\Integraciones\Auditoria;

/**
 * Cursor opaco de la paginación por (updated_at efectivo, id): JSON en
 * base64url + HMAC con la APP_KEY. Va ligado a los filtros (empresa_id y
 * updated_since): un cursor de otra consulta se rechaza en vez de devolver
 * una página incoherente.
 */
final class CursorAuditoria
{
    /**
     * @param  array{u: string, i: int}  $posicion
     */
    public static function codificar(array $posicion, string $filtros): string
    {
        $cuerpo = self::base64(json_encode(['u' => $posicion['u'], 'i' => $posicion['i'], 'f' => self::huella($filtros)], JSON_THROW_ON_ERROR));

        return sprintf('%s.%s', $cuerpo, self::firma($cuerpo));
    }

    /**
     * @return array{u: string, i: int}|null null si el cursor no es válido o es de otros filtros.
     */
    public static function decodificar(string $cursor, string $filtros): ?array
    {
        $partes = explode('.', $cursor);

        if (count($partes) !== 2 || ! hash_equals(self::firma($partes[0]), $partes[1])) {
            return null;
        }

        $json = base64_decode(strtr($partes[0], '-_', '+/'), true);
        $datos = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($datos) || ! is_string($datos['u'] ?? null) || ! is_int($datos['i'] ?? null) || ($datos['f'] ?? null) !== self::huella($filtros)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datos['u']) !== 1) {
            return null;
        }

        return ['u' => $datos['u'], 'i' => $datos['i']];
    }

    private static function firma(string $cuerpo): string
    {
        return self::base64(hash_hmac('sha256', sprintf('people-auditoria-cursor|%s', $cuerpo), (string) config('app.key'), true));
    }

    private static function huella(string $filtros): string
    {
        return substr(hash('sha256', $filtros), 0, 16);
    }

    private static function base64(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }
}
