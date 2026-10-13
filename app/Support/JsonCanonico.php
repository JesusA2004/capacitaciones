<?php

namespace App\Support;

/**
 * Comparación de estructuras JSON sin depender del orden de las llaves.
 *
 * MySQL guarda las columnas JSON con las llaves ORDENADAS (no en el orden
 * en que se escribieron), así que `json_encode($modelo->mapping)` no es
 * comparable byte a byte con un arreglo recién armado aunque el contenido
 * sea idéntico. Las listas (arreglos con índices 0..n) conservan su orden:
 * el orden de las reglas/instancias sí importa.
 */
final class JsonCanonico
{
    public static function igual(mixed $a, mixed $b): bool
    {
        return self::codificar($a) === self::codificar($b);
    }

    public static function codificar(mixed $valor): string
    {
        // Sin JSON_PRESERVE_ZERO_FRACTION: MySQL devuelve 140.0 como 140; el
        // valor es el mismo.
        return (string) json_encode(self::normalizar($valor), JSON_UNESCAPED_UNICODE);
    }

    private static function normalizar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        $normalizado = array_map(self::normalizar(...), $valor);

        if (! array_is_list($normalizado)) {
            ksort($normalizado);
        }

        return $normalizado;
    }
}
