<?php

namespace Database\Factories;

use App\Enums\EstadoAsistencia;
use App\Models\Asistencia;
use App\Models\SesionEnVivo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asistencia>
 */
class AsistenciaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sesion_en_vivo_id' => SesionEnVivo::factory(),
            'user_id' => User::factory(),
            'estado' => EstadoAsistencia::Pendiente,
        ];
    }
}
