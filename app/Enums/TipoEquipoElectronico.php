<?php

namespace App\Enums;

/**
 * Catálogo de equipo de la «RESPONSIVA DE EQUIPO ELECTRONICO» (sección 2,
 * «IDENTIFICACIÓN DEL EQUIPO»). Celular y tablet piden además IMEI y número
 * telefónico (sección 3); el resto escribe «NO APLICA». «Otro» habilita una
 * descripción libre.
 */
enum TipoEquipoElectronico: string
{
    case Computadora = 'computadora';
    case Celular = 'celular';
    case Tablet = 'tablet';
    case Monitor = 'monitor';
    case Camara = 'camara';
    case Audio = 'audio';
    case Video = 'video';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Computadora => 'Computadora / Laptop',
            self::Celular => 'Celular / Teléfono',
            self::Tablet => 'Tablet',
            self::Monitor => 'Monitor',
            self::Camara => 'Cámara',
            self::Audio => 'Equipo de audio',
            self::Video => 'Equipo de video',
            self::Otro => 'Otro',
        };
    }

    /** Un celular o tablet captura IMEI 1, IMEI 2 y número telefónico. */
    public function requiereImei(): bool
    {
        return $this === self::Celular || $this === self::Tablet;
    }
}
