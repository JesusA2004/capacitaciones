<?php

namespace App\Enums;

/**
 * Niveles de la «Certificación Mr. Lana». El umbral de cada nivel (cuántas
 * constancias hacen falta) vive en config/capacitacion.php para que el
 * negocio lo ajuste sin tocar código. Ordenados de menor a mayor.
 */
enum NivelCertificacion: string
{
    case Bronce = 'bronce';
    case Plata = 'plata';
    case Oro = 'oro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bronce => 'Mr. Lana Bronce',
            self::Plata => 'Mr. Lana Plata',
            self::Oro => 'Mr. Lana Oro',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Bronce => 'Primer paso: completaste tu primera ruta de cursos con constancia.',
            self::Plata => 'Dominas los fundamentos: varias constancias en tu historial.',
            self::Oro => 'Referente de tu equipo: la ruta completa de capacitación.',
        };
    }

    public function umbral(): int
    {
        $umbrales = config('capacitacion.certificacion.umbrales', []);
        $valor = is_array($umbrales) ? ($umbrales[$this->value] ?? null) : null;

        return is_numeric($valor) ? max(1, (int) $valor) : match ($this) {
            self::Bronce => 1,
            self::Plata => 3,
            self::Oro => 5,
        };
    }
}
