<?php

namespace App\Enums;

/**
 * Accesorios entregados con un equipo electrónico (sección 5 del formato,
 * «ACCESORIOS ENTREGADOS»). Es una lista de selección múltiple; «Ninguno» y
 * «Otro» son mutuamente excluyentes con lo demás en la práctica, pero el
 * documento solo deja constancia (no es inventario).
 */
enum AccesorioEquipoElectronico: string
{
    case Cargador = 'cargador';
    case Cable = 'cable';
    case Funda = 'funda';
    case Bateria = 'bateria';
    case Mouse = 'mouse';
    case Teclado = 'teclado';
    case Audifonos = 'audifonos';
    case Estuche = 'estuche';
    case Lente = 'lente';
    case Microfono = 'microfono';
    case Tripie = 'tripie';
    case Otro = 'otro';
    case Ninguno = 'ninguno';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cargador => 'Cargador',
            self::Cable => 'Cable',
            self::Funda => 'Funda',
            self::Bateria => 'Batería',
            self::Mouse => 'Mouse',
            self::Teclado => 'Teclado',
            self::Audifonos => 'Audífonos',
            self::Estuche => 'Estuche / mochila',
            self::Lente => 'Lente',
            self::Microfono => 'Micrófono',
            self::Tripie => 'Tripié',
            self::Otro => 'Otro',
            self::Ninguno => 'Ninguno',
        };
    }
}
