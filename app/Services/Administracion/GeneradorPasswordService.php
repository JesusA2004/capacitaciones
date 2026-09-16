<?php

namespace App\Services\Administracion;

/**
 * Genera contraseñas temporales fáciles de dictar/transcribir para un
 * colaborador (8 caracteres: 1 mayúscula, 1 dígito, 1 símbolo, el resto
 * minúsculas) en vez de una cadena totalmente aleatoria de 14 caracteres.
 * No se usa para validar contraseñas capturadas por un admin — esas siguen
 * pasando por Illuminate\Validation\Rules\Password::defaults().
 */
class GeneradorPasswordService
{
    private const MINUSCULAS = 'abcdefghjkmnpqrstuvwxyz';

    private const MAYUSCULAS = 'ABCDEFGHJKMNPQRSTUVWXYZ';

    private const DIGITOS = '23456789';

    private const SIMBOLOS = '!@#$%&*';

    public function generar(): string
    {
        $caracteres = [
            self::caracterAleatorio(self::MAYUSCULAS),
            self::caracterAleatorio(self::DIGITOS),
            self::caracterAleatorio(self::SIMBOLOS),
        ];

        for ($i = 0; $i < 5; $i++) {
            $caracteres[] = self::caracterAleatorio(self::MINUSCULAS);
        }

        shuffle($caracteres);

        return implode('', $caracteres);
    }

    private static function caracterAleatorio(string $conjunto): string
    {
        return $conjunto[random_int(0, strlen($conjunto) - 1)];
    }
}
