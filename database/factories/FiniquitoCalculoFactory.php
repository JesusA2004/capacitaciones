<?php

namespace Database\Factories;

use App\Enums\EstadoFiniquito;
use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiniquitoCalculo>
 */
class FiniquitoCalculoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sueldoMensual = $this->faker->numberBetween(6000, 20000);
        $sueldoDiario = round($sueldoMensual / 30, 2);

        return [
            'solicitud_interna_id' => SolicitudInterna::factory()->create(['tipo' => 'baja_colaborador']),
            'colaborador_id' => User::factory(),
            'calculado_por_id' => User::factory(),
            'fecha_calculo' => now(),
            'fecha_ingreso' => now()->subYears(2),
            'fecha_baja' => now(),
            'sueldo_mensual' => $sueldoMensual,
            'sueldo_diario' => $sueldoDiario,
            'antiguedad_anios' => 2,
            'antiguedad_meses' => 0,
            'dias_trabajados_periodo' => 180,
            'vacaciones_pendientes' => 6,
            'prima_vacacional' => round(6 * $sueldoDiario * 0.25, 2),
            'aguinaldo_proporcional' => round(15 * 180 / 365 * $sueldoDiario, 2),
            'sueldo_pendiente' => 0,
            'indemnizacion' => 0,
            'bonos_extra' => 0,
            'descuentos' => 0,
            'adeudos' => 0,
            'total_calculado' => 0,
            'total_ajustado' => 0,
            'estado' => EstadoFiniquito::Borrador->value,
        ];
    }
}
