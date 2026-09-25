<?php

namespace Database\Factories;

use App\Enums\MotivoCobertura;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoberturaPuesto>
 */
class CoberturaPuestoFactory extends Factory
{
    protected $model = CoberturaPuesto::class;

    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'puesto_id' => Puesto::factory(),
            'sucursal_id' => Sucursal::factory(),
            'region_id' => null,
            'motivo' => MotivoCobertura::Baja->value,
            'fecha_inicio' => now()->toDateString(),
            'activa' => true,
        ];
    }
}
