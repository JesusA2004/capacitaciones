<?php

namespace App\Enums;

/**
 * Estado del descriptivo de puesto enviado a un colaborador (se deriva de
 * las fechas del acuse — App\Models\AcuseDescriptivoPuesto::estado()).
 */
enum EstadoAcuseDescriptivoPuesto: string
{
    // RH lo envió; el colaborador todavía no lo abre.
    case Enviado = 'enviado';
    // Lo abrió en la app, aún no da su «OK, enterado».
    case Leido = 'leido';
    // Marcó «OK, enterado» y dejó su comentario.
    case Enterado = 'enterado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Enviado => 'Enviado · sin abrir',
            self::Leido => 'Leído · falta su «Enterado»',
            self::Enterado => 'Enterado',
        };
    }
}
