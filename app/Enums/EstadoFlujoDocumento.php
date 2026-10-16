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
     * Estados TERMINALES: ya no admiten ninguna transición (ni cancelación).
     * FirmadoFisicamente es final porque es el cierre del flujo NUEVO (el
     * documento que requería firma física ya la tiene y el original se
     * resguarda en papel); Archivado y Cancelado cierran el ciclo en los
     * demás casos. Los estados legacy EnviadoCorporativo/RecibidoCorporativo/
     * Escaneado NO son finales: un expediente histórico todavía puede
     * continuar su cadena antigua (recepción → escaneo → archivado).
     */
    public function esFinal(): bool
    {
        return in_array($this, [self::FirmadoFisicamente, self::Archivado, self::Cancelado], true);
    }

    /**
     * true cuando ya existe el ORIGINAL FÍSICO firmado (firma + huella
     * registradas por el gerente), aunque todavía no se envíe o archive.
     * Impreso y PendienteFirmaFisica NO cuentan: imprimir no es firmar y
     * estar pendiente de firma física tampoco. Es el criterio canónico de
     * «tiene firma física»; tieneFirmaFisica() es su alias expresivo.
     */
    public function firmaFisicaRegistrada(): bool
    {
        return in_array($this, [
            self::FirmadoFisicamente, self::EnviadoCorporativo, self::RecibidoCorporativo,
            self::Escaneado, self::Archivado,
        ], true);
    }

    /**
     * Alias expresivo de firmaFisicaRegistrada(): el original físico ya
     * quedó firmado. Úsalo como gate cuando el documento requiere firma
     * física.
     */
    public function tieneFirmaFisica(): bool
    {
        return $this->firmaFisicaRegistrada();
    }

    /**
     * true cuando el paso de firma DIGITAL del colaborador ya ocurrió. Solo
     * es un criterio válido para documentos cuyo flujo EXIGE firma digital
     * (ver ContratoLaboralService::documentoFirmadoSegunFlujo): en ese flujo
     * el documento pasa por FirmadoDigitalmente antes de cualquier estado
     * posterior, de modo que todos ellos implican que la firma digital se
     * registró. NO lo uses como gate de firma FÍSICA (para eso está
     * tieneFirmaFisica()).
     */
    public function tieneFirmaDigital(): bool
    {
        return in_array($this, [
            self::FirmadoDigitalmente, self::PendienteImpresion, self::Impreso,
            self::PendienteFirmaFisica, self::FirmadoFisicamente, self::EnviadoCorporativo,
            self::RecibidoCorporativo, self::Escaneado, self::Archivado,
        ], true);
    }

    /**
     * Criterio GENÉRICO de «el documento tiene una firma real registrada»
     * (digital o física). Un documento meramente Impreso o pendiente de
     * firma física NO cuenta como firmado: imprimir no es firmar y estar
     * pendiente de firma tampoco. Para decidir si un documento cumplió sus
     * requisitos de firma SEGÚN SU FLUJO (física si la requiere, digital si
     * solo esa), usa ContratoLaboralService::documentoFirmadoSegunFlujo(),
     * no este método.
     */
    public function estaFirmado(): bool
    {
        return $this === self::FirmadoDigitalmente || $this->tieneFirmaFisica();
    }
}
