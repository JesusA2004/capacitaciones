<?php

namespace App\Enums;

enum EstadoVacante: string
{
    case Abierta = 'abierta';
    case EnReclutamiento = 'en_reclutamiento';
    case ConCandidatos = 'con_candidatos';
    case EnRevision = 'en_revision';
    case Cubierta = 'cubierta';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierta => 'Abierta',
            self::EnReclutamiento => 'En reclutamiento',
            self::ConCandidatos => 'Con candidatos',
            self::EnRevision => 'En revisión',
            self::Cubierta => 'Cubierta',
            self::Cancelada => 'Cancelada',
        };
    }
}
