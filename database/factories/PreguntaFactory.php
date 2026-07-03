<?php

namespace Database\Factories;

use App\Enums\TipoPregunta;
use App\Models\BancoPregunta;
use App\Models\Pregunta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pregunta>
 */
class PreguntaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'banco_pregunta_id' => BancoPregunta::factory(),
            'enunciado' => fake()->sentence(6).'?',
            'tipo' => TipoPregunta::OpcionUnica,
            'puntos' => 1,
        ];
    }
}
