<?php

namespace Database\Factories;

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Models\Puesto;
use App\Models\Vacante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacante>
 */
class VacanteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'puesto_id' => Puesto::factory(),
            'motivo' => fake()->randomElement(MotivoVacante::cases())->value,
            'estado' => EstadoVacante::Abierta->value,
            'fecha_apertura' => fake()->dateTimeBetween('-1 month', 'now'),
            'observaciones' => fake()->sentence(),
        ];
    }
}
