<?php

namespace Database\Factories;

use App\Enums\EstadoExcepcionDireccionGeneral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudExcepcionPeriodoPrueba>
 */
class SolicitudExcepcionPeriodoPruebaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evaluacion_periodo_prueba_id' => EvaluacionPeriodoPrueba::factory(),
            'motivo' => 'Motivo de excepción de prueba.',
            'solicitante_id' => User::factory(),
            'estado' => EstadoExcepcionDireccionGeneral::Pendiente->value,
        ];
    }
}
