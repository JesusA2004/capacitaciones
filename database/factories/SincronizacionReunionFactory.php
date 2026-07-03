<?php

namespace Database\Factories;

use App\Enums\EstadoSincronizacionReunion;
use App\Enums\ProveedorSesion;
use App\Enums\TipoSincronizacionReunion;
use App\Models\SesionEnVivo;
use App\Models\SincronizacionReunion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SincronizacionReunion>
 */
class SincronizacionReunionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sesion_en_vivo_id' => SesionEnVivo::factory(),
            'proveedor' => ProveedorSesion::GoogleMeet,
            'tipo_sincronizacion' => TipoSincronizacionReunion::Manual,
            'estado' => EstadoSincronizacionReunion::EnProgreso,
            'iniciado_en' => now(),
            'intentos' => 1,
        ];
    }
}
