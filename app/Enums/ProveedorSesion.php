<?php

namespace App\Enums;

enum ProveedorSesion: string
{
    case Manual = 'manual';
    case GoogleMeet = 'google_meet';
    case Zoom = 'zoom';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Manual => 'Enlace manual',
            self::GoogleMeet => 'Google Meet',
            self::Zoom => 'Zoom',
        };
    }
}
