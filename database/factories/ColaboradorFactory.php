<?php

namespace Database\Factories;

use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Colaborador>
 */
class ColaboradorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'apellidos' => fake()->lastName().' '.fake()->lastName(),
            'genero' => fake()->randomElement(Genero::cases())->value,
            'numero_empleado' => fake()->unique()->numerify('EMP-#####'),
            'telefono' => fake()->numerify('##########'),
            'sueldo_mensual' => fake()->numberBetween(8000, 25000),
            'fecha_ingreso' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            // El default de columna ('activo') no siempre se aplica en
            // SQLite (motor de pruebas) cuando la columna se agrego via
            // ALTER TABLE; se fija explicitamente aqui, igual que el resto
            // de las factories del proyecto (p. ej. SucursalFactory).
            'estatus' => EstadoUsuario::Activo->value,
        ];
    }
}
