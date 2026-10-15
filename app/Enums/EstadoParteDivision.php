<?php

namespace App\Enums;

/**
 * Qué pasó con una parte del PDF histórico dividido
 * (App\Models\ParteDivisionHistorico):
 *  - asignada: se cargó al checklist como documento EN REVISIÓN (RH aprueba);
 *  - ya_cubierta: el colaborador ya tenía un documento vigente de ese tipo;
 *    la copia se conserva y RH puede asignarla a mano si la prefiere;
 *  - sin_clasificar: ninguna regla la reconoció; RH la asigna a mano;
 *  - asignada_manual: RH eligió el tipo y se cargó al checklist en revisión.
 */
enum EstadoParteDivision: string
{
    case Asignada = 'asignada';
    case YaCubierta = 'ya_cubierta';
    case SinClasificar = 'sin_clasificar';
    case AsignadaManual = 'asignada_manual';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Asignada => 'Cargada al checklist (en revisión)',
            self::YaCubierta => 'Ya había un documento vigente',
            self::SinClasificar => 'Sin clasificar',
            self::AsignadaManual => 'Asignada por RH (en revisión)',
        };
    }

    public function asignada(): bool
    {
        return $this === self::Asignada || $this === self::AsignadaManual;
    }
}
