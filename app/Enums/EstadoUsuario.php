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

    /**
     * Estados que ocupan plaza en la plantilla activa: el colaborador en
     * incorporación ya fue contratado (alta en proceso), así que su plaza ya
     * no está vacante aunque todavía no se active su acceso completo.
     *
     * @return list<string>
     */
    public static function valoresVigentes(): array
    {
        return [self::Activo->value, self::EnIncorporacion->value];
    }

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
