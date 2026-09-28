<?php

namespace App\Exceptions\Incorporacion;

use RuntimeException;

/**
 * Un QR de incorporacion invalido/vencido/revocado/usado/con correo que no
 * coincide. El controlador la atrapa y arma la respuesta JSON con `motivo` y
 * `status` — nunca deja pasar el registro cuando se lanza (ver
 * App\Services\Incorporacion\IncorporacionInvitacionService::validar()).
 */
class InvitacionInvalidaException extends RuntimeException
{
    private function __construct(string $message, public readonly string $motivo, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalido(): self
    {
        return new self('El código QR no es válido.', 'invalido', 404);
    }

    public static function vencido(): self
    {
        return new self('El código QR venció. Solicita a RH que genere uno nuevo.', 'vencido', 410);
    }

    public static function revocado(): self
    {
        return new self('El código QR fue revocado.', 'revocado', 410);
    }

    public static function usado(): self
    {
        return new self('El código QR ya fue utilizado.', 'usado', 410);
    }

    public static function correoNoCoincide(): self
    {
        return new self('El correo no coincide con la invitación.', 'correo_no_coincide', 422);
    }

    /**
     * Dos registros concurrentes con el mismo correo (mismo token de un solo
     * uso enviado dos veces a la vez, o un doble submit que ganó la
     * verificación de `tieneUsosDisponibles()` antes de que el primero
     * terminara) — la restricción única de `users.email` es el último
     * resguardo cuando el `lockForUpdate()` no alcanzó a serializar ambas
     * peticiones (ver IncorporacionInvitacionService::registrarUsuario()).
     */
    public static function correoRegistrado(): self
    {
        // 422 (no 409): el cliente móvil normaliza cualquier 409 a un
        // mensaje genérico de "esto cambió, refresca la pantalla" (ver
        // src/utils/errors.ts::normalizeError) — un 422 sí deja pasar el
        // `message` real del backend.
        return new self('Ya existe una cuenta con este correo. Si ya te registraste, inicia sesión.', 'correo_registrado', 422);
    }
}
