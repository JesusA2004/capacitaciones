<?php

namespace Database\Factories;

use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sucursal>
 */
class SucursalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Sucursal '.fake()->unique()->city(),
            'clave' => strtoupper(fake()->unique()->lexify('???')).fake()->numberBetween(10, 99),
            'direccion' => fake()->streetAddress(),
            'ciudad' => fake()->city(),
            'estado' => fake()->randomElement(['Nuevo León', 'Ciudad de México', 'Jalisco', 'Puebla', 'Querétaro']),
            'telefono' => fake()->numerify('##########'),
            'activo' => true,
        ];
    }
}
