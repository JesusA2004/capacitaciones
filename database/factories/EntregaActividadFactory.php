<?php

namespace Database\Factories;

use App\Enums\EstadoEntregaActividad;
use App\Models\Actividad;
use App\Models\EntregaActividad;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntregaActividad>
 */
class EntregaActividadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actividad_id' => Actividad::factory(),
            'user_id' => User::factory(),
            'version' => 1,
            'estado' => EstadoEntregaActividad::Entregada,
            'entregado_en' => now(),
        ];
    }
}
