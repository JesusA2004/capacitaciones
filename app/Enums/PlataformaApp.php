<?php

namespace App\Enums;

enum PlataformaApp: string
{
    case Android = 'android';
    case Ios = 'ios';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
        };
    }
}
