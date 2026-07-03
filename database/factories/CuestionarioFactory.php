<?php

namespace Database\Factories;

use App\Models\Cuestionario;
use App\Models\Leccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cuestionario>
 */
class CuestionarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leccion_id' => Leccion::factory(),
            'titulo' => rtrim(fake()->sentence(3), '.'),
            'calificacion_minima' => 80,
        ];
    }
}
