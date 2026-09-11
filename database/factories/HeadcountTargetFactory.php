<?php

namespace Database\Factories;

use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeadcountTarget>
 */
class HeadcountTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'puesto_id' => Puesto::factory(),
            'plantilla_autorizada' => fake()->numberBetween(1, 8),
            'fuente' => 'demo',
            'fecha_corte' => now()->toDateString(),
            'editable' => true,
        ];
    }
}
