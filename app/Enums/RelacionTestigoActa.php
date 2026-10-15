<?php

namespace App\Enums;

/** Relación de un testigo de HECHOS con lo ocurrido (sección V.C). */
enum RelacionTestigoActa: string
{
    case PresencioDirectamente = 'presencio_directamente';
    case ConocimientoPosterior = 'conocimiento_posterior';
    case ACargoDelArea = 'a_cargo_del_area';
    case Otra = 'otra';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PresencioDirectamente => 'Presenció directamente los hechos',
            self::ConocimientoPosterior => 'Tuvo conocimiento posterior de los hechos',
            self::ACargoDelArea => 'Estaba a cargo del área',
            self::Otra => 'Otra',
        };
    }
}
