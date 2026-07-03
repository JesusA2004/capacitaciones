<?php

namespace Database\Factories;

use App\Models\EntradaSalidaParticipante;
use App\Models\SesionParticipante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntradaSalidaParticipante>
 */
class EntradaSalidaParticipanteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inicio = now()->subMinutes(30);

        return [
            'sesion_participante_id' => SesionParticipante::factory(),
            'inicio' => $inicio,
            'fin' => $inicio->clone()->addMinutes(20),
            'duracion_segundos' => 1200,
            'origen' => 'google_meet',
        ];
    }
}
