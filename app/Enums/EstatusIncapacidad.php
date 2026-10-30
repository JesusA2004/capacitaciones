<?php

namespace App\Enums;

/**
 * Estatus DERIVADO de una incapacidad: no se guarda ni se cambia a mano, sale
 * de sus fechas y documentos (IncapacidadConsultaService). Enfermedad general
 * y maternidad concluyen al llegar su fecha de término; riesgo de trabajo,
 * al registrar el ST-2.
 */
enum EstatusIncapacidad: string
{
    case Activa = 'activa';
    case Concluida = 'concluida';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Activa => 'Activa',
            self::Concluida => 'Concluida',
            self::Cancelada => 'Cancelada',
        };
    }
}
