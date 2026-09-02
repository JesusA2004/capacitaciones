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
}
