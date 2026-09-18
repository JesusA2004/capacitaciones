<?php

namespace Database\Factories;

use App\Models\Colaborador;
use App\Models\Prestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prestamo>
 */
class PrestamoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monto = $this->faker->numberBetween(2000, 15000);
        $plazo = $this->faker->numberBetween(3, 12);

        return [
            'colaborador_id' => Colaborador::factory(),
            'solicitud_id' => null,
            'monto_original' => $monto,
            'saldo' => $monto,
            'plazo' => $plazo,
            'periodicidad' => 'quincenal',
            'pago_programado' => round($monto / $plazo, 2),
            'fecha_otorgamiento' => now(),
            'fecha_primer_descuento' => now()->addDays(15),
            'estado' => 'pendiente_entrega',
        ];
    }
}
