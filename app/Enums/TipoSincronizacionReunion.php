<?php

namespace App\Enums;

enum TipoSincronizacionReunion: string
{
    case Manual = 'manual';
    case Automatica = 'automatica';
    case Webhook = 'webhook';
    case Reintento = 'reintento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Manual => 'Manual (panel administrativo)',
            self::Automatica => 'Automática (Job)',
            self::Webhook => 'Webhook',
            self::Reintento => 'Reintento programado',
        };
    }
}
