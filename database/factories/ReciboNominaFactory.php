<?php

namespace Database\Factories;

use App\Models\Colaborador;
use App\Models\ReciboNomina;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReciboNomina>
 */
class ReciboNominaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sueldoBase = $this->faker->numberBetween(8000, 25000);
        $percepciones = [['concepto' => 'Sueldo mensual', 'monto' => $sueldoBase]];
        $deducciones = [];

        return [
            'colaborador_id' => Colaborador::factory(),
            'periodo_inicio' => now()->startOfMonth(),
            'periodo_fin' => now()->endOfMonth(),
            'fecha_pago' => now(),
            'sueldo_base' => $sueldoBase,
            'percepciones' => $percepciones,
            'deducciones' => $deducciones,
            'total_percepciones' => $sueldoBase,
            'total_deducciones' => 0,
            'neto' => $sueldoBase,
            'generado_por' => User::factory(),
            'pdf_disk' => null,
            'pdf_path' => null,
        ];
    }
}
