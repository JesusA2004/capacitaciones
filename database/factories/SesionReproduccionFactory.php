<?php

namespace Database\Factories;

use App\Models\Leccion;
use App\Models\RecursoMultimedia;
use App\Models\SesionReproduccion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SesionReproduccion>
 */
class SesionReproduccionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'leccion_id' => Leccion::factory(),
            'recurso_multimedia_id' => RecursoMultimedia::factory(),
            'iniciada_en' => now(),
            'ultima_posicion_segundos' => 0,
        ];
    }
}
