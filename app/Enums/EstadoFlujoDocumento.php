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

    public function esFinal(): bool
    {
        return $this === self::Archivado || $this === self::Cancelado;
    }

    /**
     * true cuando el documento ya quedó firmado por el colaborador (digital
     * o físicamente) — lo usan el alta (pendiente_firma) y la renovación.
     */
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

    public function estaFirmado(): bool
    {
        return in_array($this, [
            self::FirmadoDigitalmente, self::PendienteImpresion, self::Impreso,
            self::PendienteFirmaFisica, self::FirmadoFisicamente, self::EnviadoCorporativo,
            self::RecibidoCorporativo, self::Escaneado, self::Archivado,
        ], true);
    }
}
