<?php

namespace App\Enums;

/**
 * Estado de una corrida de división del PDF histórico por checklist
 * (App\Models\DivisionExpedienteHistorico, docs/DIVISION_EXPEDIENTE_HISTORICO.md).
 */
enum EstadoDivisionHistorico: string
{
    case Procesando = 'procesando';
    case Completada = 'completada';
    case Fallida = 'fallida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Procesando => 'Procesando',
            self::Completada => 'Dividido',
            self::Fallida => 'No se pudo dividir',
        };
    }
}
