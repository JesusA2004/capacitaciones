<?php

namespace Database\Factories;

use App\Enums\EstadoEvaluacionPrueba;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluacionPeriodoPrueba>
 */
class EvaluacionPeriodoPruebaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contrato_laboral_id' => ContratoLaboral::factory(),
            'colaborador_id' => fn (array $atributos) => ContratoLaboral::query()->where('id', $atributos['contrato_laboral_id'])->firstOrFail()->colaborador_id,
            'estado' => EstadoEvaluacionPrueba::Pendiente->value,
            'fecha_limite' => now()->addDays(10)->toDateString(),
        ];
    }
}
