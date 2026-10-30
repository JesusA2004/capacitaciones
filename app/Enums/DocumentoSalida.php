<?php

namespace App\Enums;

/**
 * Documento de salida que corresponde a cada forma de salida (PDF Etapa 1,
 * Pantalla 4). `clave` es la clave con la que se registra el GeneratedDocument;
 * la carta de renuncia usa el documento maestro de Jurídico y los otros tres
 * el diseño que RH administra en Documentos maestros → Documentos administrativos.
 */
enum DocumentoSalida: string
{
    case CartaRenuncia = 'carta_renuncia';
    case ConvenioTerminacion = 'convenio_terminacion';
    case AvisoRescision = 'aviso_rescision';
    case ConstanciaTerminacion = 'constancia_terminacion';

    public static function deForma(FormaSalida $forma): self
    {
        return match ($forma) {
            FormaSalida::RenunciaVoluntaria => self::CartaRenuncia,
            FormaSalida::NoRenovacion => self::ConvenioTerminacion,
            FormaSalida::Rescision => self::AvisoRescision,
            FormaSalida::OtraTerminacion => self::ConstanciaTerminacion,
        };
    }

    /**
     * Documentos que una causal de esta forma puede generar. La carta de
     * renuncia y el aviso de rescisión tienen flujo propio y no se sustituyen.
     * El primero es el de la forma.
     *
     * @return list<self>
     */
    public static function opcionesDe(FormaSalida $forma): array
    {
        return match ($forma) {
            FormaSalida::RenunciaVoluntaria => [self::CartaRenuncia],
            FormaSalida::Rescision => [self::AvisoRescision],
            FormaSalida::NoRenovacion => [self::ConvenioTerminacion, self::ConstanciaTerminacion],
            FormaSalida::OtraTerminacion => [self::ConstanciaTerminacion, self::ConvenioTerminacion],
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::CartaRenuncia => 'Carta de renuncia',
            self::ConvenioTerminacion => 'Convenio de terminación',
            self::AvisoRescision => 'Aviso de rescisión',
            self::ConstanciaTerminacion => 'Constancia de terminación',
        };
    }

    public function familia(): ?FamiliaAdministrativa
    {
        return match ($this) {
            self::CartaRenuncia => null,
            self::ConvenioTerminacion => FamiliaAdministrativa::ConvenioTerminacion,
            self::AvisoRescision => FamiliaAdministrativa::AvisoRescision,
            self::ConstanciaTerminacion => FamiliaAdministrativa::ConstanciaTerminacion,
        };
    }

    /**
     * Clave del espacio de documento requerido que cumple el firmado
     * (config/bajas.php documentos_requeridos).
     */
    public function espacioRequerido(): string
    {
        return match ($this) {
            self::CartaRenuncia => 'RV.carta_renuncia',
            self::ConvenioTerminacion => 'NR.convenio_o_aviso',
            self::AvisoRescision => 'RE.aviso_rescision',
            self::ConstanciaTerminacion => 'OT.constancia',
        };
    }
}
