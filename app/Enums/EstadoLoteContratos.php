<?php

namespace App\Enums;

/**
 * Estado de una corrida de «Generar todos» del módulo Contratos
 * (LoteGeneracionContratos).
 */
enum EstadoLoteContratos: string
{
    case EnCola = 'en_cola';
    case Procesando = 'procesando';
    case Completado = 'completado';
    case Fallido = 'fallido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnCola => 'En cola',
            self::Procesando => 'Procesando',
            self::Completado => 'Completado',
            self::Fallido => 'Falló',
        };
    }

    public function enCurso(): bool
    {
        return $this === self::EnCola || $this === self::Procesando;
    }
}
