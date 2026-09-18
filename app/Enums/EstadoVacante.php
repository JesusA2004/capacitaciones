<?php

namespace App\Enums;

/**
 * Vacantes es 100% informativo (ver docs/HEADCOUNT_Y_VACANTES.md): ya no
 * hay tablero ni transición manual de estado. Las únicas escrituras a este
 * campo las hace App\Services\Vacantes\VacanteAutoGenerationService al
 * sincronizar con el headcount (abre en Abierta, cierra a Cubierta cuando
 * ConversionColaboradorService confirma el alta, o a Cancelada cuando el
 * faltante desaparece) — nunca un usuario arrastrando una tarjeta.
 */
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
