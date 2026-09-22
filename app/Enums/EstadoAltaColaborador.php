<?php

namespace App\Enums;

/**
 * Avance administrativo del alta de un colaborador (distinto de
 * EstadoUsuario, que es la relación laboral/acceso). Lo recalcula
 * App\Services\Colaboradores\AltaColaboradorService::recalcularEstado() a
 * partir del expediente y los contratos — nunca se captura a mano, salvo
 * `activo` (activación explícita de RH) y `baja` (cierre laboral).
 */
enum EstadoAltaColaborador: string
{
    case PendienteDocumentos = 'pendiente_documentos';
    case DocumentacionEnRevision = 'documentacion_en_revision';
    case PendienteContrato = 'pendiente_contrato';
    case PendienteFirma = 'pendiente_firma';
    case PendienteActivacion = 'pendiente_activacion';
    case Activo = 'activo';
    case Baja = 'baja';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendienteDocumentos => 'Pendiente de documentos',
            self::DocumentacionEnRevision => 'Documentación en revisión',
            self::PendienteContrato => 'Pendiente de contrato',
            self::PendienteFirma => 'Pendiente de firma',
            self::PendienteActivacion => 'Pendiente de activación',
            self::Activo => 'Activo',
            self::Baja => 'Baja',
        };
    }
}
