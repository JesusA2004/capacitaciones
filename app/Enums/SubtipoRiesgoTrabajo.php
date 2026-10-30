<?php

namespace App\Enums;

/**
 * Subtipo de un riesgo de trabajo. El accidente en trayecto se identifica
 * aparte porque no se considera para la siniestralidad; este sistema solo lo
 * separa, no calcula la prima de riesgo.
 */
enum SubtipoRiesgoTrabajo: string
{
    case AccidenteTrabajo = 'accidente_trabajo';
    case AccidenteTrayecto = 'accidente_trayecto';
    case EnfermedadTrabajo = 'enfermedad_trabajo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::AccidenteTrabajo => 'Accidente de trabajo',
            self::AccidenteTrayecto => 'Accidente en trayecto',
            self::EnfermedadTrabajo => 'Enfermedad de trabajo',
        };
    }

    public function cuentaParaSiniestralidad(): bool
    {
        return $this !== self::AccidenteTrayecto;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $t): array => ['value' => $t->value, 'label' => $t->etiqueta()], self::cases());
    }
}
