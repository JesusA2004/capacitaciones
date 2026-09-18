<?php

namespace Database\Factories;

use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrestamoMovimiento>
 */
class PrestamoMovimientoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $saldoAnterior = $this->faker->numberBetween(1000, 15000);
        $monto = $this->faker->numberBetween(500, $saldoAnterior);

        return [
            'prestamo_id' => Prestamo::factory(),
            'fecha' => now(),
            'monto' => $monto,
            'tipo' => 'nomina',
            'saldo_anterior' => $saldoAnterior,
            'saldo_nuevo' => $saldoAnterior - $monto,
            'registrado_por' => User::factory(),
        ];
    }
}
