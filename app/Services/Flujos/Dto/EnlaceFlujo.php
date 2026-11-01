<?php

namespace App\Services\Flujos\Dto;

/**
 * Enlace relacionado. El href ya pasó el mismo permiso que su pantalla (ver
 * ConstructorEnlaces) y es relativo, nunca una URL libre. `descarga`: archivo,
 * no una página de Inertia.
 */
final readonly class EnlaceFlujo
{
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public string $href,
        public bool $descarga = false,
    ) {}

    /**
     * @return array{clave: string, etiqueta: string, href: string, descarga: bool}
     */
    public function toArray(): array
    {
        return ['clave' => $this->clave, 'etiqueta' => $this->etiqueta, 'href' => $this->href, 'descarga' => $this->descarga];
    }
}
