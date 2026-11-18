<?php

namespace App\Services\Ayuda;

use App\Enums\TipoMaterialAyuda;

/**
 * Un material de apoyo del catálogo (config/ayuda_material.php).
 */
final readonly class MaterialAyuda
{
    public function __construct(
        public string $slug,
        public string $titulo,
        public string $descripcion,
        public TipoMaterialAyuda $tipo,
        public string $tema,
        public string $audiencia,
        public string $archivo,
        public int $orden,
    ) {}

    /**
     * @param  array<mixed>  $datos
     */
    public static function desdeConfig(array $datos): self
    {
        return new self(
            slug: (string) ($datos['slug'] ?? ''),
            titulo: (string) ($datos['titulo'] ?? ''),
            descripcion: (string) ($datos['descripcion'] ?? ''),
            tipo: TipoMaterialAyuda::from((string) ($datos['tipo'] ?? '')),
            tema: (string) ($datos['tema'] ?? ''),
            audiencia: (string) ($datos['audiencia'] ?? ''),
            archivo: (string) ($datos['archivo'] ?? ''),
            orden: (int) ($datos['orden'] ?? 0),
        );
    }
}
