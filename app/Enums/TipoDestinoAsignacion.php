<?php

namespace App\Enums;

enum TipoDestinoAsignacion: string
{
    case Usuario = 'usuario';
    case Sucursal = 'sucursal';
    case Departamento = 'departamento';
    case Puesto = 'puesto';
    case Rol = 'rol';
    case Todos = 'todos';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Usuario => 'Usuario específico',
            self::Sucursal => 'Sucursal',
            self::Departamento => 'Departamento',
            self::Puesto => 'Puesto',
            self::Rol => 'Rol',
            self::Todos => 'Todos los colaboradores',
        };
    }
}
