<?php

namespace App\Services\Administracion;

/**
 * Contraseñas temporales (migración inicial, «Establecer contraseña» en
 * Administración > Usuarios): exactamente 8 caracteres con al menos una
 * mayúscula, una minúscula, un número y un especial de un conjunto
 * controlado (!@#$%&*?) para que se puedan dictar/copiar sin problemas. Sin
 * caracteres ambiguos (0/O, 1/l/I). Todo con random_int() (CSPRNG), incluso
 * el revuelto final. Nunca usa datos personales.
 *
 * No valida contraseñas capturadas por una persona — esas siguen pasando
 * por Illuminate\Validation\Rules\Password::defaults().
 */
class GeneradorPasswordService
{
    public const LONGITUD = 8;

    public const MINUSCULAS = 'abcdefghjkmnpqrstuvwxyz';

    public const MAYUSCULAS = 'ABCDEFGHJKMNPQRSTUVWXYZ';

    public const DIGITOS = '23456789';

    public const ESPECIALES = '!@#$%&*?';

    public function generar(): string
    {
        $caracteres = [
            self::caracterAleatorio(self::MAYUSCULAS),
            self::caracterAleatorio(self::MINUSCULAS),
            self::caracterAleatorio(self::DIGITOS),
            self::caracterAleatorio(self::ESPECIALES),
        ];
        $todos = self::MAYUSCULAS.self::MINUSCULAS.self::DIGITOS.self::ESPECIALES;

        while (count($caracteres) < self::LONGITUD) {
            $caracteres[] = self::caracterAleatorio($todos);
        }

        // Fisher–Yates con random_int (shuffle() no es criptográficamente seguro).
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }

    private static function caracterAleatorio(string $conjunto): string
    {
        return $conjunto[random_int(0, strlen($conjunto) - 1)];
    }
}
