<?php

namespace Database\Factories;

use App\Enums\AlcanceAviso;
use App\Models\Aviso;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Aviso>
 */
class AvisoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'titulo' => fake()->sentence(4),
            'mensaje' => fake()->paragraph(),
            'alcance' => AlcanceAviso::Todos->value,
            'enviado_en' => now(),
        ];
    }
}
