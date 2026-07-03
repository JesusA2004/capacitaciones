<?php

namespace App\Enums;

enum EstadoConexionIntegracion: string
{
    case Activa = 'activa';
    case Inactiva = 'inactiva';
    case Error = 'error';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Activa => 'Conectada',
            self::Inactiva => 'No configurada',
            self::Error => 'Con errores',
        };
    }
}
