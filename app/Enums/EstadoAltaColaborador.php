<?php

namespace App\Enums;

/**
 * Avance de la contratación (Etapa 2) y del onboarding (Etapa 3) de un
 * colaborador — distinto de EstadoUsuario, que es la relación laboral/acceso.
 * Lo recalcula App\Services\Colaboradores\AltaColaboradorService a partir
 * del expediente, los contratos y el onboarding; nunca se captura a mano,
 * salvo `activo` (fin del onboarding) y `baja` (cierre laboral).
 */
enum EstadoAltaColaborador: string
{
    case PendienteDocumentos = 'pendiente_documentos';
    case DocumentacionEnRevision = 'documentacion_en_revision';
    case PendienteContrato = 'pendiente_contrato';
    case PendienteFirma = 'pendiente_firma';
    case EnOnboarding = 'en_onboarding';
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
            self::EnOnboarding => 'En onboarding',
            self::PendienteActivacion => 'Pendiente de activación',
            self::Activo => 'Activo',
            self::Baja => 'Baja',
        };
    }

    /**
     * true mientras la persona está en Etapa 2 (contratación y expediente).
     */
    public function enContratacion(): bool
    {
        return in_array($this, [self::PendienteDocumentos, self::DocumentacionEnRevision, self::PendienteContrato, self::PendienteFirma], true);
    }
}
