<?php

namespace App\Enums;

/**
 * Ciclo de vida de un documento laboral generado por el motor documental
 * (App\Models\GeneratedDocument::estado_flujo). Separa deliberadamente la
 * aceptación/firma digital del colaborador de la firma física + huella y
 * del control del original físico (impresión, envío, recepción, escaneo).
 * Las transiciones válidas dependen de las banderas requiere_* del
 * documento — ver App\Services\DocumentosLaborales\FlujoDocumentalService.
 */
enum EstadoFlujoDocumento: string
{
    case Borrador = 'borrador';
    case Generado = 'generado';
    case PendienteFirmaColaborador = 'pendiente_firma_colaborador';
    case FirmadoDigitalmente = 'firmado_digitalmente';
    case PendienteImpresion = 'pendiente_impresion';
    case Impreso = 'impreso';
    case PendienteFirmaFisica = 'pendiente_firma_fisica';
    case FirmadoFisicamente = 'firmado_fisicamente';
    case EnviadoCorporativo = 'enviado_corporativo';
    case RecibidoCorporativo = 'recibido_corporativo';
    case Escaneado = 'escaneado';
    case Archivado = 'archivado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Generado => 'Generado',
            self::PendienteFirmaColaborador => 'Pendiente de firma del colaborador',
            self::FirmadoDigitalmente => 'Firmado digitalmente',
            self::PendienteImpresion => 'Pendiente de impresión',
            self::Impreso => 'Impreso',
            self::PendienteFirmaFisica => 'Pendiente de firma física',
            self::FirmadoFisicamente => 'Firmado físicamente',
            self::EnviadoCorporativo => 'Enviado a corporativo',
            self::RecibidoCorporativo => 'Recibido en corporativo',
            self::Escaneado => 'Escaneado',
            self::Archivado => 'Archivado',
            self::Cancelado => 'Cancelado',
        };
    }

    public function esFinal(): bool
    {
        return $this === self::Archivado || $this === self::Cancelado;
    }

    /**
     * true cuando el documento ya quedó firmado por el colaborador (digital
     * o físicamente) — lo usan el alta (pendiente_firma) y la renovación.
     */
    public function estaFirmado(): bool
    {
        return in_array($this, [
            self::FirmadoDigitalmente, self::PendienteImpresion, self::Impreso,
            self::PendienteFirmaFisica, self::FirmadoFisicamente, self::EnviadoCorporativo,
            self::RecibidoCorporativo, self::Escaneado, self::Archivado,
        ], true);
    }
}
