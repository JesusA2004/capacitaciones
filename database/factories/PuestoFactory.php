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
            // Cada puesto que contrata con capacitación inicial tiene su duración.
            'meses_periodo_prueba' => 2,
        ];
    }

    /** Puesto sin duración de capacitación configurada (bloquea contratar). */
    public function sinDuracionCapacitacion(): static
    {
        return $this->state(['meses_periodo_prueba' => null]);
    }
}
