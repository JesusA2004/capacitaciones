<?php

namespace App\Enums;

/**
 * Estado civil del colaborador (generales que piden los contratos y
 * convenios oficiales). La etiqueta concuerda con el género cuando se
 * conoce ("Soltera" / "Soltero").
 */
enum EstadoCivil: string
{
    case Soltero = 'soltero';
    case Casado = 'casado';
    case UnionLibre = 'union_libre';
    case Divorciado = 'divorciado';
    case Viudo = 'viudo';

    public function etiqueta(?Genero $genero = null): string
    {
        $femenino = $genero === Genero::Femenino;

        return match ($this) {
            self::Soltero => $femenino ? 'Soltera' : 'Soltero',
            self::Casado => $femenino ? 'Casada' : 'Casado',
            self::UnionLibre => 'Unión libre',
            self::Divorciado => $femenino ? 'Divorciada' : 'Divorciado',
            self::Viudo => $femenino ? 'Viuda' : 'Viudo',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], self::cases());
    }
}
