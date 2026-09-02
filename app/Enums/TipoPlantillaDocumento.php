<?php

namespace App\Enums;

enum TipoPlantillaDocumento: string
{
    case Contrato = 'contrato';
    case AvisoPrivacidad = 'aviso_privacidad';
    case ConsentimientoDatos = 'consentimiento_datos';
    case CartaConfidencialidad = 'carta_confidencialidad';
    case FormatoPermiso = 'formato_permiso';
    case FormatoVacaciones = 'formato_vacaciones';
    case FormatoIncapacidad = 'formato_incapacidad';
    case FormatoAlta = 'formato_alta';
    case FormatoBaja = 'formato_baja';
    case Resguardo = 'resguardo';
    case Acuse = 'acuse';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Contrato => 'Contrato laboral',
            self::AvisoPrivacidad => 'Aviso de privacidad',
            self::ConsentimientoDatos => 'Consentimiento de datos',
            self::CartaConfidencialidad => 'Carta de confidencialidad',
            self::FormatoPermiso => 'Formato de permiso',
            self::FormatoVacaciones => 'Formato de vacaciones',
            self::FormatoIncapacidad => 'Formato de incapacidad',
            self::FormatoAlta => 'Formato de alta',
            self::FormatoBaja => 'Formato de baja',
            self::Resguardo => 'Resguardo',
            self::Acuse => 'Acuse',
            self::Otro => 'Otro',
        };
    }
}
