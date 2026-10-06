<?php

namespace App\Enums;

/**
 * A quién llega un aviso de RH: toda la empresa (activos) o un colaborador
 * específico. Ver App\Services\Avisos\AvisoService.
 */
enum AlcanceAviso: string
{
    case Todos = 'todos';
    case Colaborador = 'colaborador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Todos => 'Toda la empresa',
            self::Colaborador => 'Un colaborador',
        };
    }
}
