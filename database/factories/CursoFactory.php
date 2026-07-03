<?php

namespace Database\Factories;

use App\Enums\EstadoCurso;
use App\Models\Curso;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Curso>
 */
class CursoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'titulo' => rtrim(fake()->sentence(4), '.'),
            'descripcion' => fake()->paragraph(),
            'objetivo' => fake()->sentence(),
            'duracion_estimada_minutos' => fake()->numberBetween(30, 240),
            'estado' => EstadoCurso::Borrador,
            'calificacion_minima' => 80,
            'requiere_orden' => true,
            'genera_constancia' => false,
            'alcance_global' => true,
        ];
    }

    public function publicado(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoCurso::Publicado,
            'publicado_en' => now(),
        ]);
    }
}
