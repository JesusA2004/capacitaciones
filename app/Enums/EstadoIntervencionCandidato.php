<?php

namespace App\Enums;

/**
 * Ciclo de vida de una intervención (CLAUDE.md §10-12): el gerente que
 * entrevistó solicita revisar un rechazo de RH; solo entonces Regional o
 * Dirección Comercial (según el puesto) deciden. Nunca es otra fase del
 * kanban de candidatos — es un proceso de excepción aparte.
 */
enum EstadoIntervencionCandidato: string
{
    case Pendiente = 'pendiente';
    case RechazoConfirmado = 'rechazo_confirmado';
    case Aprobada = 'aprobada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de decisión',
            self::RechazoConfirmado => 'Rechazo confirmado',
            self::Aprobada => 'Intervención aprobada',
        };
    }
}
