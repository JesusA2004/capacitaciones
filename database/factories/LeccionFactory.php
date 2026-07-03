<?php

namespace Database\Factories;

use App\Enums\TipoLeccion;
use App\Models\CursoModulo;
use App\Models\Leccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leccion>
 */
class LeccionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'curso_modulo_id' => CursoModulo::factory(),
            'titulo' => rtrim(fake()->sentence(3), '.'),
            'tipo' => TipoLeccion::Texto,
            'contenido' => fake()->paragraphs(2, true),
            'obligatoria' => true,
            'orden' => 0,
            'duracion_estimada_minutos' => fake()->numberBetween(5, 30),
        ];
    }
}
