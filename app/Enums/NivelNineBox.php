<?php

namespace App\Enums;

/**
 * Nivel de un eje de la Nine Box (desempeño o potencial) y de la
 * calibración del potencial que captura el jefe directo o RH
 * (docs/NINE_BOX.md).
 */
enum NivelNineBox: string
{
    case Bajo = 'bajo';
    case Medio = 'medio';
    case Alto = 'alto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bajo => 'Bajo',
            self::Medio => 'Medio',
            self::Alto => 'Alto',
        };
    }

    /** Fila/columna de la cuadrícula: 0 = bajo, 2 = alto. */
    public function indice(): int
    {
        return match ($this) {
            self::Bajo => 0,
            self::Medio => 1,
            self::Alto => 2,
        };
    }

    /**
     * Nivel de un puntaje 0-100 con los cortes configurados: menor que
     * `$corteMedio` = bajo; desde `$corteAlto` = alto; en medio = medio.
     */
    public static function dePuntaje(float $puntaje, float $corteMedio, float $corteAlto): self
    {
        return match (true) {
            $puntaje >= $corteAlto => self::Alto,
            $puntaje >= $corteMedio => self::Medio,
            default => self::Bajo,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $n): array => ['value' => $n->value, 'label' => $n->etiqueta()], self::cases());
    }
}
