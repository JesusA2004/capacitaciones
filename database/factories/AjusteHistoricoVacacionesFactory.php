<?php

namespace Database\Factories;

use App\Models\AjusteHistoricoVacaciones;
use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AjusteHistoricoVacaciones>
 */
class AjusteHistoricoVacacionesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'tipo' => 'ajuste_acumulado',
            'dias' => -8,
            'fecha_corte' => now()->toDateString(),
            'fuente' => 'manual',
        ];
    }
}
