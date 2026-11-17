<?php

namespace App\Enums;

/**
 * Qué se vincula entre PEOPLE y Reportes (tabla vinculos_reportes,
 * docs/SEGUIMIENTO.md):
 *
 *   - Sucursal: una sucursal de PEOPLE ↔ un `branch` de Reportes (oficina o
 *     ruta). Una sucursal de PEOPLE agrupa VARIOS branches; un branch solo
 *     pertenece a una sucursal a la vez.
 *   - Colaborador: un colaborador (gestor) de PEOPLE ↔ un `employee` de
 *     Reportes. Uno a uno.
 */
enum TipoVinculoReportes: string
{
    case Sucursal = 'sucursal';
    case Colaborador = 'colaborador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sucursal => 'Sucursal',
            self::Colaborador => 'Gestor / colaborador',
        };
    }
}
