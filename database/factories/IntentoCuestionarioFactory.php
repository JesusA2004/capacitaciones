<?php

namespace Database\Factories;

use App\Enums\EstadoIntentoCuestionario;
use App\Models\Cuestionario;
use App\Models\IntentoCuestionario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntentoCuestionario>
 */
class IntentoCuestionarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cuestionario_id' => Cuestionario::factory(),
            'user_id' => User::factory(),
            'numero_intento' => 1,
            'estado' => EstadoIntentoCuestionario::EnProgreso,
            'iniciado_en' => now(),
        ];
    }
}
