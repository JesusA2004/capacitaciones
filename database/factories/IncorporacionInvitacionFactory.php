<?php

namespace Database\Factories;

use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncorporacionInvitacion>
 */
class IncorporacionInvitacionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'token_hash' => hash('sha256', Str::random(64)),
            'codigo_legible' => Str::upper(Str::random(8)),
            'email' => null,
            'telefono' => null,
            'nombre_prellenado' => null,
            'creado_por_id' => User::factory(),
            'expires_at' => now()->addHours(72),
            'max_usos' => 1,
            'usos_count' => 0,
            'estado' => EstadoInvitacionIncorporacion::Activo->value,
        ];
    }

    public function vencida(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoInvitacionIncorporacion::Vencido->value,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revocada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoInvitacionIncorporacion::Revocado->value,
            'revoked_at' => now(),
        ]);
    }

    public function usada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoInvitacionIncorporacion::Usado->value,
            'usos_count' => 1,
            'used_at' => now(),
        ]);
    }
}
