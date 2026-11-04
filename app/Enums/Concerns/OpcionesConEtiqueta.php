<?php

namespace App\Enums\Concerns;

/**
 * Opciones {value, label} de un enum respaldado por string con etiqueta()
 * en español: lo que pintan los selectores de la web y de la app.
 */
trait OpcionesConEtiqueta
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $caso): array => ['value' => $caso->value, 'label' => $caso->etiqueta()], self::cases());
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $caso): string => $caso->value, self::cases());
    }
}
