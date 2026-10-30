<?php

namespace App\Enums;

/** Estatus de una cita de la agenda (pág. 25 de la propuesta de Etapa 1). */
enum EstadoAgendaEvento: string
{
    case Programado = 'programado';
    case Realizado = 'realizado';
    case NoAsistio = 'no_asistio';
    case Reprogramado = 'reprogramado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Programado => 'Programado',
            self::Realizado => 'Realizado',
            self::NoAsistio => 'Candidato no asistió',
            self::Reprogramado => 'Reprogramado',
            self::Cancelado => 'Cancelado',
        };
    }
}
