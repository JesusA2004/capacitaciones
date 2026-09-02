<?php

namespace Database\Factories;

use App\Enums\TipoSeguimientoCandidato;
use App\Models\Candidato;
use App\Models\SeguimientoCandidato;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeguimientoCandidato>
 */
class SeguimientoCandidatoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidato_id' => Candidato::factory(),
            'tipo' => fake()->randomElement(TipoSeguimientoCandidato::cases())->value,
            'nota' => fake()->sentence(),
            'fecha' => now(),
        ];
    }
}
