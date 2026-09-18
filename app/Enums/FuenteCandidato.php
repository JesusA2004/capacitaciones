<?php

namespace App\Enums;

/**
 * Canal/fuente por el que llegó un candidato al pipeline de reclutamiento.
 * Mismos valores que usa (en paralelo) el módulo de campañas de
 * reclutamiento para sus canales — no cambies estos value strings sin
 * coordinarte con ese módulo.
 */
enum FuenteCandidato: string
{
    case Meta = 'meta';
    case Indeed = 'indeed';
    case Computrabajo = 'computrabajo';
    case LinkedIn = 'linkedin';
    case Referido = 'referido';
    case Organico = 'organico';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Meta => 'Meta (Facebook/Instagram)',
            self::Indeed => 'Indeed',
            self::Computrabajo => 'Computrabajo',
            self::LinkedIn => 'LinkedIn',
            self::Referido => 'Referido',
            self::Organico => 'Orgánico',
            self::Otro => 'Otro',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $fuente) => $fuente->value, self::cases());
    }
}
