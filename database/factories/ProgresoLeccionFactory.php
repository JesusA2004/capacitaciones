<?php

namespace Database\Factories;

use App\Enums\EstadoProgreso;
use App\Models\Leccion;
use App\Models\ProgresoLeccion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgresoLeccion>
 */
class ProgresoLeccionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'leccion_id' => Leccion::factory(),
            'estado' => EstadoProgreso::Pendiente,
        ];
    }
}
