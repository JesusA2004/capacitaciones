<?php

namespace App\Enums;

/** Motivos cerrados de una entrevista no viable (pág. 25). */
enum MotivoNoViableAgenda: string
{
    case Perfil = 'perfil';
    case Actitud = 'actitud';
    case Experiencia = 'experiencia';
    case Documentacion = 'documentacion';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Perfil => 'Perfil',
            self::Actitud => 'Actitud',
            self::Experiencia => 'Experiencia',
            self::Documentacion => 'Documentación',
            self::Otro => 'Otro',
        };
    }
}
