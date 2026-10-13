<?php

namespace App\Enums;

/**
 * Qué es cada espacio de un documento maestro (document_template_fields).
 * Solo `Dato` se llena; el resto se queda EN BLANCO a propósito para el
 * acto físico (firma, huella) y nunca se interpreta como dato de la persona.
 */
enum ZonaCampoDocumento: string
{
    case Dato = 'dato';
    case Firma = 'firma';
    case Huella = 'huella';
    case Testigo = 'testigo';
    case Ignorar = 'ignorar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Dato => 'Dato',
            self::Firma => 'Firma física',
            self::Huella => 'Huella',
            self::Testigo => 'Firma de testigo',
            self::Ignorar => 'No lleva dato',
        };
    }

    public function llevaDato(): bool
    {
        return $this === self::Dato;
    }
}
