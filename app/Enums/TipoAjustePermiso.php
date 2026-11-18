<?php

namespace App\Enums;

/**
 * Ajuste de un permiso a UNA persona, encima de lo que le da su rol
 * (App\Services\Permisos\PermisosEspecialesService).
 */
enum TipoAjustePermiso: string
{
    case Conceder = 'conceder';
    case Revocar = 'revocar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Conceder => 'Concedido',
            self::Revocar => 'Quitado',
        };
    }
}
