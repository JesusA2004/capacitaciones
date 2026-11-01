<?php

namespace App\Services\Flujos\Dto;

use App\Enums\ModoAccionFlujo;

/**
 * Acción principal. Solo existe si quien consulta puede hacerla. Navegar lleva
 * href; Dialogo lleva la clave que la página abre. `soloWeb`: la app móvil no
 * tiene esa pantalla y debe decir que se completa en la web.
 */
final readonly class AccionFlujo
{
    private function __construct(
        public string $clave,
        public string $etiqueta,
        public ModoAccionFlujo $modo,
        public ?string $href,
        public bool $soloWeb,
        public bool $descarga,
    ) {}

    public static function navegar(string $clave, string $etiqueta, string $href, bool $soloWeb = true, bool $descarga = false): self
    {
        return new self($clave, $etiqueta, ModoAccionFlujo::Navegar, $href, $soloWeb, $descarga);
    }

    public static function dialogo(string $clave, string $etiqueta, bool $soloWeb = false): self
    {
        return new self($clave, $etiqueta, ModoAccionFlujo::Dialogo, null, $soloWeb, false);
    }

    /**
     * @return array{clave: string, etiqueta: string, modo: string, href: string|null, solo_web: bool, descarga: bool}
     */
    public function toArray(): array
    {
        return [
            'clave' => $this->clave,
            'etiqueta' => $this->etiqueta,
            'modo' => $this->modo->value,
            'href' => $this->href,
            'solo_web' => $this->soloWeb,
            'descarga' => $this->descarga,
        ];
    }
}
