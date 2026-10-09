<?php

namespace Database\Factories;

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\HeadcountService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacante>
 */
class VacanteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'puesto_id' => Puesto::factory(),
            'motivo' => fake()->randomElement(MotivoVacante::cases())->value,
            'estado' => EstadoVacante::Abierta->value,
            // Una vacante abierta real tiene al menos una plaza por cubrir.
            'plazas_requeridas' => 1,
            'plazas_disponibles' => 1,
            'plazas_cubiertas' => 0,
            'fecha_apertura' => fake()->dateTimeBetween('-1 month', 'now'),
            'observaciones' => fake()->sentence(),
        ];
    }

    /**
     * Vacante REAL (regla canónica: faltantes = plantilla autorizada −
     * ocupadas > 0): además de la fila, crea/aumenta la plantilla
     * autorizada de su (sucursal, puesto) para que de verdad tenga plaza.
     * Sin este estado, una vacante manual sin plantilla tiene 0 plazas y
     * no se lista (VacantesListadoService::plazasDe()).
     */
    public function real(): static
    {
        return $this
            ->state(fn (array $atributos) => ['sucursal_id' => $atributos['sucursal_id'] ?? Sucursal::factory()])
            ->afterCreating(function (Vacante $vacante): void {
                $plantilla = HeadcountTarget::query()->firstOrNew(['sucursal_id' => $vacante->sucursal_id, 'puesto_id' => $vacante->puesto_id]);

                if (! $plantilla->exists) {
                    $plantilla->forceFill(['plantilla_autorizada' => 0, 'fuente' => 'demo', 'fecha_corte' => now()->toDateString(), 'editable' => true]);
                }

                // Si ya hay ocupantes por encima de lo autorizado, primero se
                // empareja (para que la plaza nueva no nazca cubierta).
                $par = app(HeadcountService::class)->plantillaDePar((int) $vacante->sucursal_id, (int) $vacante->puesto_id);
                $plantilla->plantilla_autorizada = (int) $plantilla->plantilla_autorizada
                    + max(0, $par['ocupada'] - $par['autorizada'])
                    + max(1, (int) $vacante->plazas_disponibles);
                $plantilla->save();
            });
    }
}
