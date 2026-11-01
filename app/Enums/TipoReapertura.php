<?php

namespace App\Enums;

/**
 * Decisiones comerciales que se pueden reabrir (App\Services\Reaperturas\ReaperturaService).
 * Las bajas no están: las cancela o reabre solo Recursos Humanos.
 */
enum TipoReapertura: string
{
    case VistoBuenoSolicitud = 'visto_bueno_solicitud';
    case SolicitudRechazada = 'solicitud_rechazada';
    case VacacionesRechazadas = 'vacaciones_rechazadas';
    case IntervencionCandidato = 'intervencion_candidato';
    case EntrevistaCandidato = 'entrevista_candidato';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VistoBuenoSolicitud => 'Visto bueno',
            self::SolicitudRechazada => 'Solicitud rechazada',
            self::VacacionesRechazadas => 'Vacaciones rechazadas',
            self::IntervencionCandidato => 'Intervención',
            self::EntrevistaCandidato => 'Aprobación de entrevista',
        };
    }
}
