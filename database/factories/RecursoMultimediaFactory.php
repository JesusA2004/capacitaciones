<?php

namespace Database\Factories;

use App\Enums\EstadoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Models\RecursoMultimedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecursoMultimedia>
 */
class RecursoMultimediaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombreInterno = fake()->uuid().'.mp4';

        return [
            'tipo' => TipoRecursoMultimedia::Video,
            'nombre_original' => fake()->word().'.mp4',
            'nombre_interno' => $nombreInterno,
            'disco' => 'nas',
            'ruta_original' => "originales/{$nombreInterno}",
            'mime_type' => 'video/mp4',
            'tamano_bytes' => fake()->numberBetween(1_000_000, 50_000_000),
            'estado' => EstadoMultimedia::Disponible,
            'subido_por' => User::factory(),
        ];
    }

    public function video(): static
    {
        return $this->state(['tipo' => TipoRecursoMultimedia::Video]);
    }

    public function pendiente(): static
    {
        return $this->state(['estado' => EstadoMultimedia::Pendiente]);
    }
}
