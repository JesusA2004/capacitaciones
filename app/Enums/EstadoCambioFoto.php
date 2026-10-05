<?php

namespace App\Enums;

/**
 * Estado de una propuesta de cambio de foto de perfil
 * (App\Services\Colaboradores\FotoColaboradorService).
 */
enum EstadoCambioFoto: string
{
    case Pendiente = 'pendiente';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Cambio pendiente',
            self::Aprobado => 'Cambio aprobado',
            self::Rechazado => 'Cambio rechazado',
        };
    }
}
