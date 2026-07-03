<?php

namespace Database\Factories;

use App\Models\IntentoCuestionario;
use App\Models\Pregunta;
use App\Models\RespuestaCuestionario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RespuestaCuestionario>
 */
class RespuestaCuestionarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intento_cuestionario_id' => IntentoCuestionario::factory(),
            'pregunta_id' => Pregunta::factory(),
        ];
    }
}
