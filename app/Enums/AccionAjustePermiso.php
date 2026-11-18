<?php

namespace App\Enums;

/**
 * Renglón de la bitácora de permisos especiales de una persona.
 */
enum AccionAjustePermiso: string
{
    case Concedido = 'concedido';
    case Quitado = 'quitado';
    case Retirado = 'retirado';
    case RetiradosTodos = 'retirados_todos';
    case Vencido = 'vencido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Concedido => 'Se le concedió',
            self::Quitado => 'Se le quitó',
            self::Retirado => 'Vuelve a lo de su rol',
            self::RetiradosTodos => 'Se retiraron todos sus permisos especiales',
            self::Vencido => 'Venció el permiso especial',
        };
    }
}
