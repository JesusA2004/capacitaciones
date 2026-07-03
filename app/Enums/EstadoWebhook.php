<?php

namespace App\Enums;

enum EstadoWebhook: string
{
    case Recibido = 'recibido';
    case Procesando = 'procesando';
    case Procesado = 'procesado';
    case Descartado = 'descartado';
    case Error = 'error';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Recibido => 'Recibido',
            self::Procesando => 'Procesando',
            self::Procesado => 'Procesado',
            self::Descartado => 'Descartado (firma inválida)',
            self::Error => 'Error',
        };
    }
}
