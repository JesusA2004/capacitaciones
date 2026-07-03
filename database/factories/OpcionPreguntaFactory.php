<?php

namespace Database\Factories;

use App\Models\OpcionPregunta;
use App\Models\Pregunta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpcionPregunta>
 */
class OpcionPreguntaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pregunta_id' => Pregunta::factory(),
            'texto' => fake()->words(3, true),
            'es_correcta' => false,
            'orden' => 0,
        ];
    }
}
