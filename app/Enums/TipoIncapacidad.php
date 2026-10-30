<?php

namespace App\Enums;

/**
 * Tipos de incapacidad del IMSS que captura RH (Permanencia → Incapacidades).
 */
enum TipoIncapacidad: string
{
    case EnfermedadGeneral = 'enfermedad_general';
    case RiesgoTrabajo = 'riesgo_trabajo';
    case Maternidad = 'maternidad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnfermedadGeneral => 'Enfermedad general',
            self::RiesgoTrabajo => 'Riesgo de trabajo',
            self::Maternidad => 'Maternidad',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $t): array => ['value' => $t->value, 'label' => $t->etiqueta()], self::cases());
    }
}
