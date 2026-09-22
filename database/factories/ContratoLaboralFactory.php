<?php

namespace Database\Factories;

use App\Enums\EstadoContratoLaboral;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContratoLaboral>
 */
class ContratoLaboralFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'tipo' => TipoContratacion::PeriodoPrueba->value,
            'fecha_inicio' => now()->subDays(20)->toDateString(),
            'fecha_fin' => now()->addDays(10)->toDateString(),
            'estado' => EstadoContratoLaboral::Vigente->value,
            'sueldo_mensual' => 12000,
        ];
    }
}
