<?php

namespace App\Integrations\Reuniones\DTO;

use Illuminate\Support\Carbon;

/**
 * Una entrada/salida real de un participante, ya normalizada desde el
 * formato propio del proveedor (participantSessions de Meet, join/leave de
 * Zoom).
 */
final readonly class SesionParticipanteExterna
{
    public function __construct(
        public Carbon $inicio,
        public ?Carbon $fin,
        public string $origen,
        public ?string $identificadorExterno = null,
    ) {}

    public function duracionSegundos(): ?int
    {
        return $this->fin ? (int) $this->inicio->diffInSeconds($this->fin) : null;
    }
}
