<?php

namespace Database\Factories;

use App\Enums\TipoEntregaActividad;
use App\Models\Actividad;
use App\Models\Leccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Actividad>
 */
class ActividadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leccion_id' => Leccion::factory(),
            'titulo' => rtrim(fake()->sentence(3), '.'),
            'instrucciones' => fake()->paragraph(),
            'tipo_entrega' => TipoEntregaActividad::Archivo,
            'calificacion_minima' => 80,
        ];
    }
}
