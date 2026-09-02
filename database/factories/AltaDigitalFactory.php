<?php

namespace Database\Factories;

use App\Enums\EstadoAltaDigital;
use App\Models\AltaDigital;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AltaDigital>
 */
class AltaDigitalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => Str::random(48),
            'token_expira_en' => now()->addDays(7),
            'estado' => EstadoAltaDigital::Creada->value,
            'nombre' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'correo' => fake()->unique()->safeEmail(),
        ];
    }
}
