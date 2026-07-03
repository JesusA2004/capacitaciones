<?php

namespace Database\Factories;

use App\Enums\EstadoConexionIntegracion;
use App\Enums\ProveedorSesion;
use App\Models\ConexionIntegracion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConexionIntegracion>
 */
class ConexionIntegracionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proveedor' => ProveedorSesion::GoogleMeet,
            'estado' => EstadoConexionIntegracion::Inactiva,
        ];
    }
}
