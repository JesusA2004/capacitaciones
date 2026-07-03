<?php

namespace App\Enums;

/**
 * Cómo se decide si un participante "cumplió" la sesión: por porcentaje
 * visto, por minutos absolutos, o basta con cumplir cualquiera de los dos
 * (el más flexible). Configurable por sesión (SesionEnVivo). Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 2.
 */
enum CriterioCumplimientoAsistencia: string
{
    case Porcentaje = 'porcentaje';
    case Minutos = 'minutos';
    case Cualquiera = 'cualquiera';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Porcentaje => 'Porcentaje mínimo de la sesión',
            self::Minutos => 'Minutos mínimos',
            self::Cualquiera => 'Cumplir porcentaje o minutos (lo que se alcance primero)',
        };
    }
}
