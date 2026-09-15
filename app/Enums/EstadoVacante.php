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

    /**
     * Mapa de transiciones válidas para App\Http\Controllers\Rh\VacanteController::actualizarEstado()
     * (drag and drop del tablero, ver docs/VACANTES.md). "Cubierta" NUNCA es
     * un destino válido aquí a propósito: solo se llega a ese estado a
     * través de VacanteController::cubrir() (cobertura real: colaborador
     * interno, temporal o candidato externo), nunca soltando una tarjeta.
     */
    public function puedeTransicionarA(self $destino): bool
    {
        if ($this === self::Cubierta || $this === self::Cancelada) {
            return false;
        }

        if ($destino === self::Cancelada) {
            return true;
        }

        if ($destino === self::Cubierta) {
            return false;
        }

        return match ($this) {
            self::Abierta => in_array($destino, [self::EnReclutamiento, self::ConCandidatos], true),
            self::EnReclutamiento => in_array($destino, [self::ConCandidatos, self::EnRevision], true),
            self::ConCandidatos => $destino === self::EnRevision,
            self::EnRevision => $destino === self::ConCandidatos,
        };
    }
}
