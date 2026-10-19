<?php

namespace App\Enums;

/**
 * Ciclo de vida de un documento laboral generado por el motor documental
 * (App\Models\GeneratedDocument::estado_flujo). Separa deliberadamente la
 * aceptación/firma digital del colaborador de la firma física + huella.
 *
 * Flujo NUEVO (documentos que se imprimen y firman en PAPEL): el documento
 * queda CONCLUIDO al registrarse su firma física (FirmadoFisicamente es el
 * estado final). El original firmado se resguarda físicamente; ya NO se
 * envía a corporativo, ni se recibe, ni se escanea, ni se archiva como pasos
 * obligatorios.
 *
 * Los casos EnviadoCorporativo, RecibidoCorporativo, Escaneado y Archivado
 * se conservan SOLO para LEER expedientes legacy que recorrieron el flujo
 * anterior (no se eliminan: romperían la lectura de esos registros). Ningún
 * flujo nuevo los exige ni los ofrece como acción.
 *
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

    /**
     * Etiqueta corta que ven RH y el gerente en las tarjetas de documentos y
     * en el historial del ciclo laboral (la MISMA en ambos lugares): un
     * documento recién generado dice «Generado» y su acción es «Imprimir».
     */
    public function etiquetaCorta(bool $requiereFirmaFisica): string
    {
        return match ($this) {
            self::Generado, self::PendienteImpresion => 'Generado',
            self::Impreso => $requiereFirmaFisica ? 'Firma pendiente' : 'Impreso',
            self::PendienteFirmaFisica => 'Firma pendiente',
            self::FirmadoFisicamente => 'Firmado',
            self::EnviadoCorporativo => 'Enviado',
            self::RecibidoCorporativo => 'Recibido',
            default => $this->etiqueta(),
        };
    }

    /**
     * Estado terminal administrativo: el documento ya se archivó o se canceló.
     * NO incluye FirmadoFisicamente a propósito — para «ya no se puede tocar
     * el documento» (incluida la firma física, estado final del flujo en
     * papel) usa estaConcluido().
     */
    public function esFinal(): bool
    {
        return $this === self::Archivado || $this === self::Cancelado;
    }

    /**
     * El documento ya no admite cambios de flujo: está firmado físicamente
     * (estado FINAL del flujo en papel, con su original resguardado),
     * archivado o cancelado. Un documento concluido NO puede cancelarse sin
     * un proceso de anulación auditado aparte — ver FlujoDocumentalService::
     * cancelar().
     */
    public function estaConcluido(): bool
    {
        return $this === self::FirmadoFisicamente || $this->esFinal();
    }

    /**
     * true cuando ya existe el original físico firmado (firma + huella
     * registradas por el gerente), aunque todavía no se envíe o archive.
     */
    public function firmaFisicaRegistrada(): bool
    {
        return in_array($this, [
            self::FirmadoFisicamente, self::EnviadoCorporativo, self::RecibidoCorporativo,
            self::Escaneado, self::Archivado,
        ], true);
    }

    /**
     * true cuando el colaborador YA ACEPTÓ/FIRMÓ digitalmente el documento (y
     * en cualquier estado posterior). OJO: NO significa «concluido según su
     * flujo» — devuelve true incluso en estados PRE-firma física
     * (PendienteImpresion/Impreso/PendienteFirmaFisica), porque solo marca
     * que se superó la etapa de firma digital. Para saber si el documento
     * cumplió todos sus requisitos de firma usa
     * App\Services\DocumentosLaborales\FirmaDocumentalService::
     * documentoConcluidoSegunFlujo(), no este método.
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
