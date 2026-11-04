<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Último grado de estudios de la persona (Solicitud de empleo: «Último grado de estudios»).
 */
enum Escolaridad: string
{
    use OpcionesConEtiqueta;

    case SinEstudios = 'sin_estudios';
    case Primaria = 'primaria';
    case Secundaria = 'secundaria';
    case Bachillerato = 'bachillerato';
    case CarreraTecnica = 'carrera_tecnica';
    case LicenciaturaTrunca = 'licenciatura_trunca';
    case Licenciatura = 'licenciatura';
    case Posgrado = 'posgrado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinEstudios => 'Sin estudios',
            self::Primaria => 'Primaria',
            self::Secundaria => 'Secundaria',
            self::Bachillerato => 'Bachillerato',
            self::CarreraTecnica => 'Carrera técnica',
            self::LicenciaturaTrunca => 'Licenciatura trunca',
            self::Licenciatura => 'Licenciatura terminada',
            self::Posgrado => 'Posgrado',
        };
    }
}
