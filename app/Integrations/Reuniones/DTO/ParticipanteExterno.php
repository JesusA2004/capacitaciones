<?php

namespace App\Integrations\Reuniones\DTO;

/**
 * Un participante detectado por el proveedor, ya normalizado. `correo` solo
 * se llena cuando el proveedor lo entrega con certeza razonable (nunca se
 * infiere de un nombre); si es null, App\Services\Reuniones\AsociadorParticipanteService
 * lo deja como pendiente_revision o anónimo, nunca lo asocia por adivinanza.
 */
final readonly class ParticipanteExterno
{
    /**
     * @param  array<int, SesionParticipanteExterna>  $sesiones
     */
    public function __construct(
        public string $identificadorExterno,
        public ?string $correo,
        public ?string $nombre,
        public array $sesiones,
    ) {}
}
