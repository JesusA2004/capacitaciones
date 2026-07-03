<?php

namespace Database\Factories;

use App\Enums\CriterioCumplimientoAsistencia;
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
            // Mismos valores por defecto que la migración, para que una
            // instancia recién creada en memoria (sin ->fresh()) ya los
            // tenga disponibles — Eloquent no rehidrata columnas con
            // DEFAULT de la base de datos automáticamente tras create().
            'porcentaje_minimo_asistencia' => 80,
            'minutos_minimos_asistencia' => null,
            'tolerancia_minutos' => 5,
            'criterio_cumplimiento' => CriterioCumplimientoAsistencia::Porcentaje,
            'considerar_tiempo_previo' => false,
            'considerar_tiempo_posterior' => false,
        ];
    }
}
