<?php

namespace Database\Factories;

use App\Enums\EstadoSolicitudVacaciones;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudVacaciones>
 */
class SolicitudVacacionesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inicio = fake()->dateTimeBetween('+1 week', '+2 months');

        return [
            'user_id' => User::factory(),
            'fecha_inicio' => $inicio,
            'fecha_fin' => (clone $inicio)->modify('+4 days'),
            'dias_solicitados' => 5,
            'estado' => EstadoSolicitudVacaciones::Pendiente->value,
        ];
    }
}
