<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * «Generar credenciales» (docs/AUTENTICACION.md): RH da acceso a cualquier
 * colaborador SIN correo; se muestra usuario + contraseña temporal una vez.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->rh = clUsuario('rh_admin');
});

test('colaborador sin cuenta ni correo: se crea su cuenta con usuario y contraseña temporal', function () {
    $colaborador = Colaborador::factory()->create(['name' => 'JESUS ENRIQUE', 'apellidos' => 'ARIZMENDI PEREZ', 'correo_personal' => null]);

    $datos = $this->actingAs($this->rh)->postJson(route('rh.colaboradores.credenciales', $colaborador))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('data');

    $cuenta = User::query()->where('colaborador_id', $colaborador->id)->firstOrFail();

    expect($datos['usuario'])->toBe('Jesus Arizmendi')
        ->and($datos['cuenta_nueva'])->toBeTrue()
        ->and(strlen($datos['contrasena']))->toBe(8)
        ->and($cuenta->email)->toBeNull()
        ->and($cuenta->debe_cambiar_contrasena)->toBeTrue()
        ->and($cuenta->hasRole('colaborador'))->toBeTrue()
        ->and($cuenta->password)->not->toBe($datos['contrasena'])
        ->and(Hash::check($datos['contrasena'], $cuenta->password))->toBeTrue();

    // Y con eso entra (sin correo) y se le pide cambiarla.
    $this->post(route('logout'));
    $this->post(route('login.store'), ['username' => 'jesus arizmendi', 'password' => $datos['contrasena']]);
    $this->assertAuthenticatedAs($cuenta);
    $this->get(route('dashboard'))->assertRedirect(route('contrasena-temporal.edit'));
});

test('colaborador con cuenta: conserva su usuario y recibe otra contraseña temporal', function () {
    $colaborador = Colaborador::factory()->create(['name' => 'Maria', 'apellidos' => 'Lopez Ramirez']);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuarioAntes = $cuenta->username;

    $datos = $this->actingAs($this->rh)->postJson(route('rh.colaboradores.credenciales', $colaborador))->assertOk()->json('data');

    expect($datos['usuario'])->toBe($usuarioAntes)
        ->and($datos['cuenta_nueva'])->toBeFalse()
        ->and(Hash::check($datos['contrasena'], $cuenta->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $cuenta->fresh()->password))->toBeFalse()
        ->and($cuenta->fresh()->debe_cambiar_contrasena)->toBeTrue()
        ->and(User::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);
});

test('baja: no se generan credenciales', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => EstadoUsuario::Inactivo]);

    $this->actingAs($this->rh)->postJson(route('rh.colaboradores.credenciales', $colaborador))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credenciales');

    expect(User::query()->where('colaborador_id', $colaborador->id)->exists())->toBeFalse();
});

test('sin permiso o sobre la propia cuenta: 403', function () {
    $colaborador = Colaborador::factory()->create();

    $this->actingAs(clUsuario('colaborador'))->postJson(route('rh.colaboradores.credenciales', $colaborador))->assertForbidden();
    $this->actingAs($this->rh)->postJson(route('rh.colaboradores.credenciales', $this->rh->colaborador))->assertForbidden();
});

test('API móvil de RH usa el mismo servicio', function () {
    $colaborador = Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Sanchez Mora', 'correo_personal' => null]);

    $this->actingAs($this->rh, 'sanctum')->postJson(route('api.v1.rh.colaboradores.credenciales', $colaborador))
        ->assertOk()
        ->assertJsonPath('data.usuario', 'Luis Sanchez');
});

test('nuevo usuario desde Administración ya no pide correo', function () {
    $colaborador = Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Ruiz Paz']);

    $this->actingAs($this->rh)->post(route('administracion.usuarios.store'), ['colaborador_id' => $colaborador->id, 'roles' => []])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('colaborador_id', $colaborador->id)->value('username'))->toBe('Ana Ruiz');
});
