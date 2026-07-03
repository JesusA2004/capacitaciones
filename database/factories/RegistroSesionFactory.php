<?php

namespace Database\Factories;

use App\Enums\EstadoSincronizacion;
use App\Enums\ProveedorSesion;
use App\Models\RegistroSesion;
use App\Models\SesionEnVivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistroSesion>
 */
class RegistroSesionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sesion_en_vivo_id' => SesionEnVivo::factory(),
            'proveedor' => ProveedorSesion::GoogleMeet,
            'estado_sincronizacion' => EstadoSincronizacion::Pendiente,
            'intentos' => 0,
        ];
    }
}
