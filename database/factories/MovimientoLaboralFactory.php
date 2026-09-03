<?php

namespace Database\Factories;

use App\Enums\TipoMovimientoLaboral;
use App\Models\MovimientoLaboral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoLaboral>
 */
class MovimientoLaboralFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tipo_movimiento' => TipoMovimientoLaboral::AjusteManual->value,
            'fecha_movimiento' => fake()->dateTimeBetween('-1 year', 'now'),
            'observaciones' => fake()->sentence(),
        ];
    }
}
