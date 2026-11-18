<?php

namespace App\Enums;

/**
 * QUÉ registros de un módulo puede ver alguien (no si puede entrar ni qué
 * acciones hace: eso son permisos aparte). Se configura por rol y por
 * módulo en Roles y permisos (config/permisos_modulos.php) y lo resuelve
 * App\Services\Permisos\AlcanceModuloService en el backend.
 */
enum AlcanceDatos: string
{
    case Propios = 'propios';
    case Equipo = 'equipo';
    case Sucursal = 'sucursal';
    // Su sucursal + TODO el personal de Corporativo (sucursal con
    // es_corporativo), pero no las demás sucursales. Ej.: un gerente que en
    // el Directorio necesita ver a su gente y a las áreas de Corporativo.
    case SucursalCorporativo = 'sucursal_corporativo';
    case Region = 'region';
    case Todos = 'todos';

    public function etiqueta(): string
    {
        return match ($this) {
            // Explícitas (lo pidió el negocio): mismo texto que
            // resources/js/lib/rolesPermisos.ts → ETIQUETA_NIVEL.
            self::Propios => 'Solo lo propio',
            self::Equipo => 'Lo suyo + su equipo directo',
            self::Sucursal => 'Solo su sucursal',
            self::SucursalCorporativo => 'Su sucursal + Corporativo (no las demás sucursales)',
            self::Region => 'Las sucursales de su región',
            self::Todos => 'Todas las sucursales (toda la empresa)',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Propios => 'Solo los registros de la propia persona.',
            self::Equipo => 'Los suyos y los de quienes le reportan directamente.',
            self::Sucursal => 'Los de su sucursal (y las adicionales que tenga asignadas). Ninguna otra.',
            self::SucursalCorporativo => 'Los de su sucursal más todo el personal de Corporativo. No ve las demás sucursales.',
            self::Region => 'Los de todas las sucursales de su región (matriz comercial).',
            self::Todos => 'Toda la organización: todas las sucursales y Corporativo.',
        };
    }

    /**
     * true si el nivel se resuelve con una lista de sucursales (Sucursal,
     * Sucursal y corporativo, Región). Ver AlcanceModuloService::sucursalesPara().
     */
    public function esPorSucursales(): bool
    {
        return in_array($this, [self::Sucursal, self::SucursalCorporativo, self::Region], true);
    }

    /**
     * De mayor a menor alcance: el primero que la persona tenga, gana.
     * «Sucursal y corporativo» va entre Región y Sucursal: amplía la
     * sucursal, pero no reemplaza a una región ya concedida.
     *
     * @return list<self>
     */
    public static function deMayorAMenor(): array
    {
        return [self::Todos, self::Region, self::SucursalCorporativo, self::Sucursal, self::Equipo];
    }
}
