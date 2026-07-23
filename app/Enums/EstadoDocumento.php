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
        };
    }
}
