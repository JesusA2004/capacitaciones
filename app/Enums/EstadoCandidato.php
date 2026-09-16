<?php

namespace App\Enums;

enum EstadoCandidato: string
{
    case Nuevo = 'nuevo';
    case Contactado = 'contactado';
    case Respondio = 'respondio';
    case NoRespondio = 'no_respondio';
    case Viable = 'viable';
    case NoViable = 'no_viable';
    case EntrevistaProgramada = 'entrevista_programada';
    case Entrevistado = 'entrevistado';
    case DocumentacionSolicitada = 'documentacion_solicitada';
    case EnRevision = 'en_revision';
    case AprobadoGerencia = 'aprobado_gerencia';
    case AprobadoRh = 'aprobado_rh';
    case Rechazado = 'rechazado';
    case Descartado = 'descartado';
    case Contratado = 'contratado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Nuevo => 'Nuevo',
            self::Contactado => 'Contactado',
            self::Respondio => 'Respondió',
            self::NoRespondio => 'No respondió',
            self::Viable => 'Viable',
            self::NoViable => 'No viable',
            self::EntrevistaProgramada => 'Entrevista programada',
            self::Entrevistado => 'Entrevistado',
            self::DocumentacionSolicitada => 'Documentación solicitada',
            self::EnRevision => 'En revisión',
            self::AprobadoGerencia => 'Aprobado por gerencia',
            self::AprobadoRh => 'Aprobado por RH',
            self::Rechazado => 'Rechazado',
            self::Descartado => 'Descartado',
            self::Contratado => 'Contratado',
        };
    }

    /**
     * Los 4 estados terminales de salida (rechazo/descarte) no forman parte
     * del pipeline lineal: pueden alcanzarse desde cualquier fase abierta,
     * así que no llevan un número de orden propio.
     */
    private const ESTADOS_SALIDA = [self::NoRespondio, self::NoViable, self::Rechazado, self::Descartado];

    /**
     * Posición en el pipeline sucesivo de un candidato. Usado por
     * puedeTransicionarA() para prohibir retrocesos (ver docs del método).
     */
    public function orden(): int
    {
        return match ($this) {
            self::Nuevo => 1,
            self::Contactado => 2,
            self::Respondio => 3,
            self::Viable => 4,
            self::EntrevistaProgramada => 5,
            self::Entrevistado => 6,
            self::DocumentacionSolicitada => 7,
            self::EnRevision => 8,
            self::AprobadoGerencia => 9,
            self::AprobadoRh => 10,
            self::Contratado => 11,
            self::NoRespondio, self::NoViable, self::Rechazado, self::Descartado => 0,
        };
    }

    public function esTerminal(): bool
    {
        return $this === self::Contratado || in_array($this, self::ESTADOS_SALIDA, true);
    }

    /**
     * Mapa de transiciones válidas para App\Http\Controllers\Rh\CandidatoController::actualizarEstado()
     * (drag and drop del tablero de candidatos). Las fases de un candidato son
     * sucesivas: nunca se permite retroceder ni saltar hacia atrás en el
     * pipeline. Un estado terminal (Contratado o cualquiera de los 4 estados
     * de salida) es definitivo: no admite ningún cambio posterior.
     */
    public function puedeTransicionarA(self $destino): bool
    {
        if ($this->esTerminal()) {
            return false;
        }

        if (in_array($destino, self::ESTADOS_SALIDA, true)) {
            return true;
        }

        return $destino->orden() > $this->orden();
    }
}
