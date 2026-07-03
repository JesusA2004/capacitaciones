<?php

namespace Database\Factories;

use App\Models\Curso;
use App\Models\CursoModulo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CursoModulo>
 */
class CursoModuloFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'curso_id' => Curso::factory(),
            'titulo' => rtrim(fake()->sentence(3), '.'),
            'descripcion' => fake()->sentence(),
            'orden' => 0,
        ];
    }
}
