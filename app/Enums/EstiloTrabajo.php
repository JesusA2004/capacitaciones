<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * «Tu estilo de trabajo» de la Solicitud de empleo (se pueden elegir varios).
 */
enum EstiloTrabajo: string
{
    use OpcionesConEtiqueta;

    case OrientadoMetas = 'orientado_metas';
    case EnfocadoCliente = 'enfocado_cliente';
    case TrabajoCampo = 'trabajo_campo';
    case TrabajoPresion = 'trabajo_presion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OrientadoMetas => 'Orientado a metas',
            self::EnfocadoCliente => 'Enfocado al cliente',
            self::TrabajoCampo => 'Trabajo en campo',
            self::TrabajoPresion => 'Trabajo bajo presión',
        };
    }
}
