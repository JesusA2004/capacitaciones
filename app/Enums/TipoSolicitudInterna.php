<?php

namespace App\Enums;

/**
 * Tipos de solicitud interna (docs/SOLICITUDES_UNIFICADAS.md). Vacaciones,
 * préstamo interno y baja de colaborador viven aquí — reestructuración que
 * unifica lo que antes eran módulos separados (Vacaciones tenía su propia
 * tabla `solicitudes_vacaciones`, hoy sin uso nuevo — se conserva intacta,
 * sin datos que migrar porque estaba vacía al unificar).
 */
enum TipoSolicitudInterna: string
{
    case Vacaciones = 'vacaciones';
    case PermisoConGoce = 'permiso_con_goce';
    case PermisoSinGoce = 'permiso_sin_goce';
    case Incapacidad = 'incapacidad';
    case ConstanciaLaboral = 'constancia_laboral';
    case ActualizacionDatos = 'actualizacion_datos';
    case ActualizacionBancaria = 'actualizacion_bancaria';
    case ReposicionDocumental = 'reposicion_documental';
    case PrestamoInterno = 'prestamo_interno';
    case BajaColaborador = 'baja_colaborador';
    case General = 'general';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vacaciones => 'Vacaciones',
            self::PermisoConGoce => 'Permiso con goce de sueldo',
            self::PermisoSinGoce => 'Permiso sin goce de sueldo',
            self::Incapacidad => 'Incapacidad',
            self::ConstanciaLaboral => 'Constancia laboral',
            self::ActualizacionDatos => 'Actualización de datos',
            self::ActualizacionBancaria => 'Actualización bancaria',
            self::ReposicionDocumental => 'Reposición documental',
            self::PrestamoInterno => 'Préstamo interno',
            self::BajaColaborador => 'Baja de colaborador',
            self::General => 'Solicitud general',
        };
    }

    /**
     * true si el tipo usa un rango de fechas (vacaciones/permisos/incapacidad);
     * el resto solo usa `motivo`/`observaciones` en texto libre.
     */
    public function usaRangoFechas(): bool
    {
        return match ($this) {
            self::Vacaciones, self::PermisoConGoce, self::PermisoSinGoce, self::Incapacidad => true,
            default => false,
        };
    }

    /**
     * true si el tipo requiere `dias_solicitados` (se valida contra el
     * saldo disponible del colaborador, ver VacacionesService::saldo()).
     */
    public function requiereDias(): bool
    {
        return $this === self::Vacaciones;
    }

    /**
     * true si el tipo requiere `monto_solicitado` (y opcionalmente
     * `plazo_meses`).
     */
    public function requiereMonto(): bool
    {
        return $this === self::PrestamoInterno;
    }

    /**
     * true si quien solicita no es el sujeto de la solicitud: la crea un
     * gerente/RH sobre OTRO colaborador (`colaborador_objetivo_id`).
     */
    public function requiereColaboradorObjetivo(): bool
    {
        return $this === self::BajaColaborador;
    }

    /**
     * Slug del formato oficial que corresponde generar para este tipo (ver
     * App\Services\Formatos\OfficialFormatCatalogoService). null si el tipo
     * no tiene un formato oficial asociado.
     */
    public function formatoOficialSlug(): ?string
    {
        return match ($this) {
            self::Vacaciones => 'formato-vacaciones',
            self::PermisoConGoce, self::PermisoSinGoce => 'formato-permiso',
            self::PrestamoInterno => 'contrato-credito-colaboradores',
            self::BajaColaborador => 'formato-baja-personal',
            default => null,
        };
    }
}
