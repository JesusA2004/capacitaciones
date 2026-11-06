<?php

namespace App\Support;

/**
 * Textos de relleno que algunas capturas dejaron en lugar de un dato real
 * («REGISTRADO», «S/D», «N/A», «PENDIENTE», «---»…). Nunca se imprimen en un
 * documento ni cuentan como dato capturado: se tratan como vacío para que el
 * motor los pida (DATOS_FALTANTES) en vez de imprimir el marcador.
 */
final class DatoMarcador
{
    private const PATRON = '/^(no\s+)?registrad[oa]s?$|^(s\/?d|n\/?a|n\/?d|sin\s+datos?|pendiente|por\s+registrar|x+|-+|\.+|_+)$/iu';

    public static function es(?string $texto): bool
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));

        return $limpio !== '' && preg_match(self::PATRON, $limpio) === 1;
    }

    /** El texto recortado, o '' si es un marcador de relleno. */
    public static function limpiar(?string $texto): string
    {
        $limpio = trim((string) $texto);

        return self::es($limpio) ? '' : $limpio;
    }
}
