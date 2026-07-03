<?php

namespace Database\Factories;

use App\Enums\EstadoIdentificacionParticipante;
use App\Enums\TipoParticipante;
use App\Models\RegistroSesion;
use App\Models\SesionParticipante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SesionParticipante>
 */
class SesionParticipanteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registro_sesion_id' => RegistroSesion::factory(),
            'correo_detectado' => fake()->safeEmail(),
            'nombre_mostrado' => fake()->name(),
            'tipo_participante' => TipoParticipante::Externo,
            'estado_identificacion' => EstadoIdentificacionParticipante::PendienteRevision,
            'minutos_acumulados' => 0,
            'porcentaje_sesion' => 0,
            'numero_reconexiones' => 0,
        ];
    }
}
