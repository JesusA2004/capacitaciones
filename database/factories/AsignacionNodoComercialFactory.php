<?php

namespace Database\Factories;

use App\Enums\TipoAsignacionNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\NodoComercial;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AsignacionNodoComercial>
 */
class AsignacionNodoComercialFactory extends Factory
{
    protected $model = AsignacionNodoComercial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nodo_comercial_id' => NodoComercial::factory(),
            'tipo_asignacion' => TipoAsignacionNodoComercial::Gestor->value,
            'activo' => true,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => null,
        ];
    }
}
