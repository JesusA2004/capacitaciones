<?php

namespace App\Enums;

enum TipoMovimientoLaboral: string
{
    case Alta = 'alta';
    case Baja = 'baja';
    case Promocion = 'promocion';
    case CambioPuesto = 'cambio_puesto';
    case CambioSucursal = 'cambio_sucursal';
    case CambioDepartamento = 'cambio_departamento';
    case CambioJefe = 'cambio_jefe';
    case CambioEmpresa = 'cambio_empresa';
    case CoberturaTemporal = 'cobertura_temporal';
    case Reingreso = 'reingreso';
    case AjusteManual = 'ajuste_manual';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Alta => 'Alta',
            self::Baja => 'Baja',
            self::Promocion => 'Promoción',
            self::CambioPuesto => 'Cambio de puesto',
            self::CambioSucursal => 'Cambio de sucursal',
            self::CambioDepartamento => 'Cambio de departamento',
            self::CambioJefe => 'Cambio de jefe',
            self::CambioEmpresa => 'Cambio de empresa',
            self::CoberturaTemporal => 'Cobertura temporal',
            self::Reingreso => 'Reingreso',
            self::AjusteManual => 'Ajuste manual',
        };
    }

    /**
     * Ícono Lucide sugerido para timelines (nombre del componente, sin
     * importar aquí: el frontend mapea este valor a su propio ícono).
     */
    public function icono(): string
    {
        return match ($this) {
            self::Alta, self::Reingreso => 'UserPlus',
            self::Baja => 'UserMinus',
            self::Promocion => 'TrendingUp',
            self::CambioPuesto => 'Briefcase',
            self::CambioSucursal => 'Building2',
            self::CambioDepartamento => 'Building',
            self::CambioJefe => 'UserCog',
            self::CambioEmpresa => 'Landmark',
            self::CoberturaTemporal => 'Shuffle',
            self::AjusteManual => 'Pencil',
        };
    }
}
