<?php

namespace Database\Factories;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Una cuenta de acceso sin colaborador enlazado no puede
            // iniciar sesión de verdad (FortifyServiceProvider::authenticateUsing(),
            // Api\V1\AuthController::login() leen colaborador->estatus, no
            // users.estatus — ver docs/ROLES_Y_NAVEGACION.md): un
            // Colaborador activo por default aquí es lo que hace que
            // User::factory()->create() sirva para un login real en tests,
            // no solo para actingAs().
            'colaborador_id' => Colaborador::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // El default de columna ('activo') no siempre se aplica en
            // SQLite (motor de pruebas) cuando la columna se agrego via
            // ALTER TABLE; se fija explicitamente aqui, igual que el resto
            // de las factories del proyecto (p. ej. SucursalFactory).
            // Columna legacy (ver Colaborador::estatus para la fuente
            // real), pero se deja en 'activo' por si algo todavía la lee.
            'estatus' => EstadoUsuario::Activo->value,
        ];
    }

    /**
     * Datos de la PERSONA que muchas pruebas todavía pasan a la cuenta
     * (`User::factory()->create(['sucursal_principal_id' => ...])`, herencia
     * de antes de la separación User/Colaborador): se copian a su
     * Colaborador, que es de donde los lee todo el sistema (alcance,
     * headcount, cumpleaños, login...). Sin esto la prueba creaba a la
     * persona en otra sucursal/puesto al azar y fallaba por razones ajenas a
     * lo que verifica. Solo aplica a lo que la prueba pasó explícitamente.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $usuario): void {
            $colaborador = $usuario->colaborador;

            if ($colaborador === null) {
                return;
            }

            $atributos = $usuario->getAttributes();
            $datos = [];

            foreach (['sucursal_principal_id', 'puesto_id', 'departamento_id', 'fecha_nacimiento', 'fecha_ingreso', 'numero_empleado', 'foto_path'] as $campo) {
                if (($atributos[$campo] ?? null) !== null) {
                    $datos[$campo] = $atributos[$campo];
                }
            }

            $estatus = $atributos['estatus'] ?? null;

            if ($estatus !== null && $estatus !== EstadoUsuario::Activo->value) {
                $datos['estatus'] = $estatus instanceof EstadoUsuario ? $estatus->value : $estatus;
            }

            if ($datos !== []) {
                $colaborador->update($datos);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     *
     * No-op hasta que se implemente 2FA (columnas two_factor_* y
     * Features::twoFactorAuthentication() en config/fortify.php).
     */
    public function withTwoFactor(): static
    {
        return $this;
    }
}
