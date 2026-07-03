<?php

namespace Database\Factories;

use App\Enums\EstadoMultimedia;
use App\Models\CargaMultimedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CargaMultimedia>
 */
class CargaMultimediaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nombre_original' => fake()->word().'.mp4',
            'tamano_total_bytes' => 10_000_000,
            'bytes_recibidos' => 0,
            'estado' => EstadoMultimedia::Cargando,
        ];
    }
}
