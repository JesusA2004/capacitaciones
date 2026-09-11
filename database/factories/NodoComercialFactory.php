<?php

namespace Database\Factories;

use App\Enums\TipoNodoComercial;
use App\Models\NodoComercial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodoComercial>
 */
class NodoComercialFactory extends Factory
{
    protected $model = NodoComercial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'tipo' => TipoNodoComercial::Ruta->value,
            'nombre' => fake()->unique()->city(),
            'activa' => true,
            'orden' => 0,
        ];
    }
}
