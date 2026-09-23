<?php

namespace App\Enums;

/**
 * Tipos de solicitud interna (docs/SOLICITUDES_UNIFICADAS.md). Vacaciones,
 * préstamo interno y baja de colaborador viven aquí — reestructuración que
 * unifica lo que antes eran módulos separados (Vacaciones tenía su propia
 * tabla `solicitudes_vacaciones`, hoy solo endpoint legacy — ver
 * App\Http\Controllers\VacacionesController — cuyos días sí se descuentan
 * del mismo saldo, ver App\Services\Vacaciones\VacacionesService::saldo()).
 */
enum TipoSolicitudInterna: string
{
    case Vacaciones = 'vacaciones';
    case PermisoConGoce = 'permiso_con_goce';
    case PermisoSinGoce = 'permiso_sin_goce';
    case PermisoTiempo = 'permiso_tiempo';
    case SalidaTemprano = 'salida_temprano';
    case LlegadaTarde = 'llegada_tarde';
    case Incapacidad = 'incapacidad';
    case ConstanciaLaboral = 'constancia_laboral';
    case ActualizacionDatos = 'actualizacion_datos';
    case ActualizacionBancaria = 'actualizacion_bancaria';
    case ReposicionDocumental = 'reposicion_documental';
    case PrestamoInterno = 'prestamo';
    case BajaColaborador = 'baja_colaborador';
    case PermisoEspecialCumpleanos = 'permiso_especial_cumpleanos';
    case PermisoEspecialPaternidad = 'permiso_especial_paternidad';
    case PermisoEspecialFallecimiento = 'permiso_especial_fallecimiento';
    case General = 'solicitud_general';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vacaciones => 'Vacaciones',
            self::PermisoConGoce => 'Permiso con goce de sueldo',
            self::PermisoSinGoce => 'Permiso sin goce de sueldo',
            self::PermisoTiempo => 'Permiso por tiempo (horas)',
            self::SalidaTemprano => 'Salida temprano',
            self::LlegadaTarde => 'Llegada tarde',
            self::Incapacidad => 'Incapacidad',
            self::ConstanciaLaboral => 'Constancia laboral',
            self::ActualizacionDatos => 'Actualización de datos',
            self::ActualizacionBancaria => 'Actualización bancaria',
            self::ReposicionDocumental => 'Reposición documental',
            self::PrestamoInterno => 'Préstamo interno',
            self::BajaColaborador => 'Baja de colaborador',
            self::PermisoEspecialCumpleanos => 'Permiso especial: cumpleaños',
            self::PermisoEspecialPaternidad => 'Permiso especial: paternidad',
            self::PermisoEspecialFallecimiento => 'Permiso especial: fallecimiento',
            self::General => 'Solicitud general',
        };
    }

    /**
     * true si el tipo usa un rango de fechas (vacaciones/permisos por
     * día(s)/incapacidad/permisos especiales de varios días); los que se
     * resuelven en un solo día con horario (ver usaHorario()) y el resto
     * (préstamo, baja, actualización de datos, etc.) no lo usan.
     */
    public function usaRangoFechas(): bool
    {
        return match ($this) {
            self::Vacaciones,
            self::PermisoConGoce,
            self::PermisoSinGoce,
            self::Incapacidad,
            self::PermisoEspecialPaternidad,
            self::PermisoEspecialFallecimiento => true,
            default => false,
        };
    }

    /**
     * true si el tipo ocurre en un solo día con hora de inicio/fin (salida
     * temprano, llegada tarde, permiso por horas) en vez de un rango de
     * fechas completas.
     */
    public function usaHorario(): bool
    {
        return match ($this) {
            self::PermisoTiempo, self::SalidaTemprano, self::LlegadaTarde => true,
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
     * true si el propio colaborador puede crear este tipo desde la app
     * (autoservicio). La baja NO: un colaborador no solicita su baja; es un
     * proceso administrativo de RH/Dirección.
     */
    public function creableEnAutoservicio(): bool
    {
        return $this !== self::BajaColaborador;
    }

    /**
     * @return list<string>
     */
    public static function valoresAutoservicio(): array
    {
        return array_values(array_map(
            fn (self $tipo) => $tipo->value,
            array_filter(self::cases(), fn (self $tipo) => $tipo->creableEnAutoservicio()),
        ));
    }

    /**
     * Slug del formato oficial que corresponde generar para este tipo (ver
     * config/solicitudes.php y App\Services\Formatos\OfficialFormatOverlayService).
     * null si el tipo no tiene un formato oficial asociado.
     */
    public function formatoOficialSlug(): ?string
    {
        return config("solicitudes.formatos.{$this->value}.slug");
    }

    /**
     * En qué transición del flujo se genera el formato oficial:
     * 'creacion' | 'aprobacion' | 'cierre'. null si el tipo no tiene
     * formato asociado.
     */
    public function formatoGenerarEn(): ?string
    {
        return config("solicitudes.formatos.{$this->value}.generar_en");
    }

    /**
     * true si el formato generado para este tipo espera que RH suba de
     * vuelta el PDF firmado. false (incluyendo tipos sin formato asociado)
     * si no aplica.
     */
    public function formatoRequiereFirma(): bool
    {
        return (bool) config("solicitudes.formatos.{$this->value}.requiere_firma", false);
    }
}
