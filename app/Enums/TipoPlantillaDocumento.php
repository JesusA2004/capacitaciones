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
    case ConstanciaLaboral = 'constancia_laboral';
    case ActualizacionDatos = 'actualizacion_datos';
    case ReposicionDocumental = 'reposicion_documental';
    case SolicitudGeneral = 'solicitud_general';
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
            self::ConstanciaLaboral => 'Constancia laboral',
            self::ActualizacionDatos => 'Actualización de datos',
            self::ReposicionDocumental => 'Reposición documental',
            self::SolicitudGeneral => 'Solicitud general',
            self::Resguardo => 'Resguardo',
            self::Acuse => 'Acuse',
            self::Otro => 'Otro',
        };
    }

    /**
     * Tipo de plantilla sugerido para un TipoSolicitudInterna, usado para
     * preseleccionar/filtrar plantillas al generar un formato desde una
     * solicitud. Devuelve null cuando no hay un mapeo directo (el usuario
     * elige la plantilla manualmente).
     */
    public static function paraTipoSolicitud(TipoSolicitudInterna $tipo): ?self
    {
        return match ($tipo) {
            TipoSolicitudInterna::PermisoConGoce,
            TipoSolicitudInterna::PermisoSinGoce => self::FormatoPermiso,
            TipoSolicitudInterna::Incapacidad => self::FormatoIncapacidad,
            TipoSolicitudInterna::ConstanciaLaboral => self::ConstanciaLaboral,
            TipoSolicitudInterna::ActualizacionDatos,
            TipoSolicitudInterna::ActualizacionBancaria => self::ActualizacionDatos,
            TipoSolicitudInterna::ReposicionDocumental => self::ReposicionDocumental,
            TipoSolicitudInterna::General => self::SolicitudGeneral,
            default => null,
        };
    }
}
