<?php

namespace App\Enums;

/**
 * Tipo de recurso gráfico de la biblioteca de documentos (DocumentAsset).
 */
enum TipoDocumentAsset: string
{
    case Fondo = 'background';
    case Logo = 'logo';
    case MarcaAgua = 'watermark';
    case Imagen = 'image';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Fondo => 'Fondo de página',
            self::Logo => 'Logo',
            self::MarcaAgua => 'Marca de agua',
            self::Imagen => 'Imagen',
        };
    }
}
