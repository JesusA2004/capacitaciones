<?php

namespace App\Enums;

enum TipoSeguimientoCandidato: string
{
    case Llamada = 'llamada';
    case Correo = 'correo';
    case Entrevista = 'entrevista';
    case CambioEstado = 'cambio_estado';
    case Nota = 'nota';
    case Documento = 'documento';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Llamada => 'Llamada',
            self::Correo => 'Correo',
            self::Entrevista => 'Entrevista',
            self::CambioEstado => 'Cambio de estado',
            self::Nota => 'Nota',
            self::Documento => 'Documento',
            self::Otro => 'Otro',
        };
    }
}
