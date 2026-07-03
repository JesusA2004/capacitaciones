<?php

namespace Database\Factories;

use App\Enums\EstadoAsignacion;
use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AsignacionUsuario>
 */
class AsignacionUsuarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asignacion_id' => Asignacion::factory(),
            'user_id' => User::factory(),
            'estado' => EstadoAsignacion::Pendiente,
        ];
    }
}
