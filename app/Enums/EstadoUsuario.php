<?php

namespace App\Enums;

enum EstadoUsuario: string
{
    case Activo = 'activo';
    case Inactivo = 'inactivo';
    case Suspendido = 'suspendido';

    /**
     * Colaborador que ya tiene cuenta (puede iniciar sesion en la app
     * movil) pero todavia no queda activo: esta completando/esperando la
     * revision de su expediente documental (ver
     * App\Services\Incorporacion\IncorporacionService). RH lo pasa a
     * Activo al aprobar la incorporacion.
     */
    case EnIncorporacion = 'en_incorporacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Inactivo => 'Inactivo',
            self::Suspendido => 'Suspendido',
            self::EnIncorporacion => 'En incorporación',
        };
    }
}
