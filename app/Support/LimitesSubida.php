<?php

namespace App\Support;

/**
 * Límites de subida REALES del proceso PHP que atiende la petición (PHP-FPM
 * en producción, no el CLI): upload_max_filesize y post_max_size.
 *
 * Existe para diagnosticar el 413 sin adivinar: el panel de versiones de la
 * app los muestra tal cual los ve FPM, y bootstrap/app.php los registra en
 * el log cuando una subida excede post_max_size (ValidatePostSize →
 * PostTooLargeException → 413 «Content Too Large»).
 */
final class LimitesSubida
{
    /**
     * Bytes de un valor de php.ini («300M», «1G», «512K», «0» = sin límite → null).
     */
    public static function bytes(string $valor): ?int
    {
        $valor = trim($valor);

        if ($valor === '' || $valor === '0' || $valor === '-1') {
            return null;
        }

        $numero = (int) $valor;

        return match (strtoupper(substr($valor, -1))) {
            'G' => $numero * 1024 * 1024 * 1024,
            'M' => $numero * 1024 * 1024,
            'K' => $numero * 1024,
            default => $numero,
        };
    }

    /**
     * @return array{sapi: string, upload_max_filesize: string, post_max_size: string, memory_limit: string, max_input_time: string, max_execution_time: string}
     */
    public static function resumen(): array
    {
        return [
            'sapi' => PHP_SAPI,
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_input_time' => (string) ini_get('max_input_time'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
        ];
    }

    /**
     * MB que realmente cabe en una subida: lo menor entre el límite de la
     * aplicación y lo que PHP acepta (archivo y petición completa).
     */
    public static function efectivoMb(int $limiteAplicacionMb): int
    {
        $candidatos = [$limiteAplicacionMb];

        foreach (['upload_max_filesize', 'post_max_size'] as $clave) {
            $bytes = self::bytes((string) ini_get($clave));

            if ($bytes !== null) {
                $candidatos[] = intdiv($bytes, 1024 * 1024);
            }
        }

        return max(0, min($candidatos));
    }
}
