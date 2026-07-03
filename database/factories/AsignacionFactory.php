<?php

namespace Database\Factories;

use App\Models\Asignacion;
use App\Models\Curso;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asignacion>
 */
class AsignacionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => rtrim(fake()->sentence(3), '.'),
            'asignable_type' => Curso::class,
            'asignable_id' => Curso::factory(),
            'responsable_id' => User::factory(),
            'obligatoria' => true,
            'activa' => true,
        ];
    }
}
