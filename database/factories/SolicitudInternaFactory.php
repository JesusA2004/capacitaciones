<?php

namespace Database\Factories;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudInterna>
 */
class SolicitudInternaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => 'SOL-'.$this->faker->unique()->numerify('######'),
            'user_id' => User::factory(),
            'tipo' => TipoSolicitudInterna::General->value,
            'estado' => EstadoSolicitudInterna::Enviada->value,
            'motivo' => $this->faker->sentence(),
        ];
    }
}
