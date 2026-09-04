<?php

namespace App\Enums;

enum EstadoDocumento: string
{
    case Pendiente = 'pendiente';
    case Cargado = 'cargado';
    case EnRevision = 'en_revision';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';
    case RequiereCorreccion = 'requiere_correccion';
    case Vencido = 'vencido';
    case Archivado = 'archivado';

    /** El colaborador ya subio el documento y pidio poder reemplazarlo; espera autorizacion de RH (ver IncorporacionService::solicitarCambio). */
    case CambioSolicitado = 'cambio_solicitado';

    /** RH autorizo el reemplazo solicitado: el colaborador ya puede subir la nueva version (ver IncorporacionService::autorizarCambio). */
    case CambioAutorizado = 'cambio_autorizado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Cargado => 'Cargado',
            self::EnRevision => 'En revisión',
            self::Aprobado => 'Aprobado',
            self::Rechazado => 'Rechazado',
            self::RequiereCorreccion => 'Requiere corrección',
            self::Vencido => 'Vencido',
            self::Archivado => 'Archivado',
            self::CambioSolicitado => 'Cambio solicitado',
            self::CambioAutorizado => 'Cambio autorizado',
        };
    }
}
