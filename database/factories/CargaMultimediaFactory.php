<?php

namespace Database\Factories;

use App\Enums\EstadoCargaMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Models\CargaMultimedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        $tamanoBloque = 5_000_000;
        $tamanoTotal = 10_000_000;

        return [
            'identificador' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'nombre_original' => fake()->word().'.mp4',
            'tipo' => TipoRecursoMultimedia::Video,
            'tamano_total_bytes' => $tamanoTotal,
            'tamano_bloque_bytes' => $tamanoBloque,
            'total_bloques' => (int) ceil($tamanoTotal / $tamanoBloque),
            'bytes_recibidos' => 0,
            'bloques_recibidos' => [],
            'estado' => EstadoCargaMultimedia::EnProgreso,
            'expira_en' => now()->addHours(24),
        ];
    }
}
