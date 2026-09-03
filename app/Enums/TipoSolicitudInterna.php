<?php

namespace App\Enums;

/**
 * Tipos de solicitud interna (docs/SOLICITUDES_INTERNAS.md). Vacaciones NO
 * está aquí a propósito: ya tiene su propio módulo completo
 * (SolicitudVacaciones/VacacionesService) y no se duplica esa lógica —
 * "Solicitudes internas" cubre el resto de trámites de RH del día a día.
 */
enum TipoSolicitudInterna: string
{
    case PermisoConGoce = 'permiso_con_goce';
    case PermisoSinGoce = 'permiso_sin_goce';
    case Incapacidad = 'incapacidad';
    case ConstanciaLaboral = 'constancia_laboral';
    case ActualizacionDatos = 'actualizacion_datos';
    case ActualizacionBancaria = 'actualizacion_bancaria';
    case ReposicionDocumental = 'reposicion_documental';
    case PrestamoInterno = 'prestamo_interno';
    case General = 'general';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PermisoConGoce => 'Permiso con goce de sueldo',
            self::PermisoSinGoce => 'Permiso sin goce de sueldo',
            self::Incapacidad => 'Incapacidad',
            self::ConstanciaLaboral => 'Constancia laboral',
            self::ActualizacionDatos => 'Actualización de datos',
            self::ActualizacionBancaria => 'Actualización bancaria',
            self::ReposicionDocumental => 'Reposición documental',
            self::PrestamoInterno => 'Préstamo interno',
            self::General => 'Solicitud general',
        };
    }

    /**
     * true si el tipo usa un rango de fechas (permisos/incapacidad); el
     * resto solo usa `motivo`/`observaciones` en texto libre.
     */
    public function usaRangoFechas(): bool
    {
        return match ($this) {
            self::PermisoConGoce, self::PermisoSinGoce, self::Incapacidad => true,
            default => false,
        };
    }
}
