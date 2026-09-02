<?php

namespace Database\Factories;

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidato>
 */
class CandidatoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'correo' => fake()->unique()->safeEmail(),
            'fuente' => fake()->randomElement(['referido', 'bolsa_trabajo', 'redes_sociales', 'volante']),
            'estado' => EstadoCandidato::Nuevo->value,
        ];
    }
}
