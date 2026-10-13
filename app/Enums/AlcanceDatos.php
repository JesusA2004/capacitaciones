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
    case Region = 'region';
    case Todos = 'todos';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Propios => 'Propios',
            self::Equipo => 'Equipo',
            self::Sucursal => 'Sucursal',
            self::Region => 'Región',
            self::Todos => 'Todos',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Propios => 'Solo los registros de la propia persona.',
            self::Equipo => 'Los suyos y los de quienes le reportan directamente.',
            self::Sucursal => 'Los de sus sucursales asignadas.',
            self::Region => 'Los de todas las sucursales de su región (matriz comercial).',
            self::Todos => 'Toda la organización.',
        };
    }

    /**
     * De mayor a menor alcance: el primero que la persona tenga, gana.
     *
     * @return list<self>
     */
    public static function deMayorAMenor(): array
    {
        return [self::Todos, self::Region, self::Sucursal, self::Equipo];
    }
}
