<?php

namespace App\Enums;

/**
 * Por qué alguien cubre temporalmente un puesto que no es el suyo
 * (App\Models\CoberturaPuesto).
 */
enum MotivoCobertura: string
{
    case Baja = 'baja';
    case Incapacidad = 'incapacidad';
    case Vacaciones = 'vacaciones';
    case Vacante = 'vacante';
    case Apoyo = 'apoyo';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Baja => 'Baja del titular',
            self::Incapacidad => 'Incapacidad del titular',
            self::Vacaciones => 'Vacaciones del titular',
            self::Vacante => 'Puesto vacante, en contratación',
            self::Apoyo => 'Apoyo temporal',
            self::Otro => 'Otro motivo',
        };
    }

    /**
     * @return list<array{value: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            fn (self $motivo) => ['value' => $motivo->value, 'etiqueta' => $motivo->etiqueta()],
            self::cases(),
        );
    }
}
