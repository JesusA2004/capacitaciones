<?php

namespace App\Enums;

/**
 * Cómo se escribe un dato en el documento (lista cerrada: nunca código).
 */
enum TransformacionTexto: string
{
    case Mayusculas = 'mayusculas';
    case Minusculas = 'minusculas';
    case Titulo = 'titulo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Mayusculas => 'MAYÚSCULAS',
            self::Minusculas => 'minúsculas',
            self::Titulo => 'Tipo Título',
        };
    }

    public function aplicar(string $valor): string
    {
        return match ($this) {
            self::Mayusculas => mb_strtoupper($valor, 'UTF-8'),
            self::Minusculas => mb_strtolower($valor, 'UTF-8'),
            self::Titulo => mb_convert_case(mb_strtolower($valor, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
        };
    }
}
