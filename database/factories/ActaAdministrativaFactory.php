<?php

namespace Database\Factories;

use App\Enums\EstadoActa;
use App\Enums\TipoActa;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActaAdministrativa>
 */
class ActaAdministrativaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'tipo' => TipoActa::Hechos->value,
            'estado' => EstadoActa::Borrador->value,
            'fecha' => now()->toDateString(),
            'hora' => '10:00',
            'lugar' => 'Sucursal',
            'hechos' => $this->faker->paragraph(),
        ];
    }
}
