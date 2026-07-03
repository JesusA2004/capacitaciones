<?php

namespace Database\Factories;

use App\Models\BancoPregunta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BancoPregunta>
 */
class BancoPreguntaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => rtrim(fake()->sentence(3), '.'),
            'descripcion' => fake()->sentence(8),
            'creado_por' => User::factory(),
        ];
    }
}
