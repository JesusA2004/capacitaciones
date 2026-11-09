<?php

namespace App\Enums;

/**
 * Por qué un puesto activo del catálogo no aparece en el árbol General
 * (no se llega a él desde Dirección General por puestos activos). Ver
 * App\Services\Organigrama\PuestosSinUbicarService.
 */
enum MotivoPuestoSinUbicar: string
{
    /** No tiene «reporta a». */
    case SinSuperior = 'sin_superior';

    /** Reporta a un puesto inactivo o eliminado. */
    case SuperiorInactivo = 'superior_inactivo';

    /** Su superior existe pero tampoco cuelga de Dirección General (o hay un ciclo). */
    case RamaSuelta = 'rama_suelta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinSuperior => 'No tiene a quién reportar',
            self::SuperiorInactivo => 'Reporta a un puesto inactivo o eliminado',
            self::RamaSuelta => 'Su superior tampoco está en el organigrama',
        };
    }
}
