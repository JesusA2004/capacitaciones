<?php

namespace Database\Factories;

use App\Models\Colaborador;
use App\Models\HistorialSalario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HistorialSalario>
 */
class HistorialSalarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'salario_diario_anterior' => null,
            'salario_diario_nuevo' => 300,
            'fecha_efectiva' => now()->toDateString(),
            'fuente' => 'manual',
        ];
    }
}
