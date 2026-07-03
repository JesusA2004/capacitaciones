<?php

namespace Database\Factories;

use App\Enums\EstadoProgreso;
use App\Models\Curso;
use App\Models\InscripcionCurso;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InscripcionCurso>
 */
class InscripcionCursoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'curso_id' => Curso::factory(),
            'estado' => EstadoProgreso::Pendiente,
        ];
    }
}
