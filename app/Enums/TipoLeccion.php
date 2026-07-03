<?php

namespace App\Enums;

enum TipoLeccion: string
{
    case Video = 'video';
    case Documento = 'documento';
    case Guia = 'guia';
    case Texto = 'texto';
    case Enlace = 'enlace';
    case Cuestionario = 'cuestionario';
    case Actividad = 'actividad';
    case SesionEnVivo = 'sesion_en_vivo';
    case Confirmacion = 'confirmacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Video => 'Video',
            self::Documento => 'Documento',
            self::Guia => 'Guía',
            self::Texto => 'Texto',
            self::Enlace => 'Enlace',
            self::Cuestionario => 'Cuestionario',
            self::Actividad => 'Actividad',
            self::SesionEnVivo => 'Sesión en vivo',
            self::Confirmacion => 'Confirmación de lectura',
        };
    }
}
