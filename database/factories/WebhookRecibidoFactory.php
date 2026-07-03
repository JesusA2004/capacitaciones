<?php

namespace Database\Factories;

use App\Enums\EstadoWebhook;
use App\Enums\ProveedorSesion;
use App\Models\WebhookRecibido;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookRecibido>
 */
class WebhookRecibidoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proveedor' => ProveedorSesion::Zoom,
            'identificador_evento' => (string) Str::uuid(),
            'tipo' => 'meeting.ended',
            'hash_payload' => hash('sha256', (string) Str::uuid()),
            'firma_valida' => true,
            'estado' => EstadoWebhook::Recibido,
            'intentos' => 0,
        ];
    }
}
