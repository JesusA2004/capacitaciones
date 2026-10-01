<?php

namespace App\Enums;

enum TipoEvidenciaCandidato: string
{
    case Fotografia = 'fotografia';
    case Video = 'video';
    case Documento = 'documento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Fotografia => 'Fotografía',
            self::Video => 'Video',
            self::Documento => 'Documento',
        };
    }

    public static function desdeMime(?string $mime): self
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => self::Fotografia,
            str_starts_with((string) $mime, 'video/') => self::Video,
            default => self::Documento,
        };
    }
}
