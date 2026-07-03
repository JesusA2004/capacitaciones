<?php

namespace Database\Factories;

use App\Enums\EstadoSesionEnVivo;
use App\Enums\ProveedorSesion;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SesionEnVivo>
 */
class SesionEnVivoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leccion_id' => Leccion::factory(),
            'titulo' => rtrim(fake()->sentence(3), '.'),
            'proveedor' => ProveedorSesion::Manual,
            'fecha_inicio' => now()->addDay(),
            'duracion_minutos' => 60,
            'estado' => EstadoSesionEnVivo::Programada,
            'creado_por' => User::factory(),
        ];
    }
}
