<?php

namespace Database\Factories;

use App\Models\Departamento;
use App\Models\Puesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Puesto>
 */
class PuestoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->jobTitle(),
            'departamento_id' => Departamento::factory(),
            'descripcion' => fake()->sentence(),
            'activo' => true,
        ];
    }
}
