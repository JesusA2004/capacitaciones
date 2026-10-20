<?php

namespace App\Enums;

/**
 * Forma de entrega del aviso de rescisión (RN-07/RN-08). El aviso es un
 * documento que se ADJUNTA a la baja; el sistema no lo genera.
 */
enum EntregaAvisoRescision: string
{
    case Personal = 'personal';
    case AnteTribunal = 'ante_tribunal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Personal => 'Personal',
            self::AnteTribunal => 'Ante Tribunal',
        };
    }
}
