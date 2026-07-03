<?php

namespace App\Integrations\Reuniones\DTO;

use Illuminate\Support\Carbon;

/**
 * Datos normalizados del registro de conferencia recuperado del proveedor,
 * listos para persistirse en RegistroSesion + SesionParticipante +
 * EntradaSalidaParticipante (App\Services\Reuniones\SincronizacionAsistenciaService).
 */
final readonly class RegistroSesionExterno
{
    /**
     * @param  array<int, ParticipanteExterno>  $participantes
     * @param  array<string, mixed>  $respuestaNormalizada
     */
    public function __construct(
        public ?string $identificadorExterno,
        public ?string $registroConferenciaExterno,
        public ?Carbon $inicioReal,
        public ?Carbon $finReal,
        public ?int $duracionRealSegundos,
        public array $participantes,
        public array $respuestaNormalizada,
    ) {}
}
