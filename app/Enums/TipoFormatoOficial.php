<?php

namespace App\Enums;

/**
 * Categoria de un App\Models\OfficialFormat (formato oficial fijo de MR.
 * LANA, ver docs/FORMATOS_OFICIALES.md) — solo para agrupar/filtrar en el
 * catalogo, no cambia el flujo de generacion.
 */
enum TipoFormatoOficial: string
{
    case Vacaciones = 'vacaciones';
    case Permiso = 'permiso';
    case SolicitudEmpleo = 'solicitud_empleo';
    case Contrato = 'contrato';
    case Baja = 'baja';
    case Embarazo = 'embarazo';
    case Lactancia = 'lactancia';
    case Capacitacion = 'capacitacion';
    case Documentos = 'documentos';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vacaciones => 'Vacaciones',
            self::Permiso => 'Permiso',
            self::SolicitudEmpleo => 'Solicitud de empleo',
            self::Contrato => 'Contrato',
            self::Baja => 'Baja de personal',
            self::Embarazo => 'Embarazo',
            self::Lactancia => 'Lactancia',
            self::Capacitacion => 'Capacitación',
            self::Documentos => 'Documentos',
            self::Otro => 'Otro',
        };
    }
}
