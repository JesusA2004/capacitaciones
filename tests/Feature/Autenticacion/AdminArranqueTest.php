<?php

use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/*
 * Arranque de producción: la cuenta super_admin con la que se entra a un
 * sistema recién migrado es OPERATIVA y no tiene Colaborador enlazado, así
 * que puede iniciar sesión pero no aparece en expedientes/plantilla. Un
 * colaborador dado de baja sigue bloqueado; una cuenta sin colaborador y sin
 * acceso operativo tampoco entra. Ver App\Services\Autenticacion\
 * AutenticacionService::puedeIniciarSesion() y App\Console\Commands\
 * CrearAdminCommand.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un super_admin operativo SIN colaborador puede iniciar sesión en la web', function () {
    $admin = User::factory()->create(['colaborador_id' => null, 'password' => 'secreta123']);
    $admin->assignRole('super_admin');

    $this->post(route('login.store'), ['username' => $admin->username, 'password' => 'secreta123'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($admin);
});

test('una cuenta sin colaborador y sin acceso operativo NO puede iniciar sesión', function () {
    // Sin rol operativo (sin dashboard.*.ver) y sin colaborador: no es ni
    // empleado ni administrador, no debe entrar.
    $cuenta = User::factory()->create(['colaborador_id' => null, 'password' => 'secreta123']);

    $this->post(route('login.store'), ['username' => $cuenta->username, 'password' => 'secreta123'])
        ->assertSessionHasErrors(['username' => 'Tu cuenta está desactivada. Contacta a Recursos Humanos.']);

    $this->assertGuest('web');
});

test('un colaborador dado de baja sigue sin poder iniciar sesión aunque tenga un rol operativo', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => 'inactivo']);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id, 'password' => 'secreta123']);
    $cuenta->assignRole('super_admin');

    $this->post(route('login.store'), ['username' => $cuenta->username, 'password' => 'secreta123'])
        ->assertSessionHasErrors(['username' => 'Tu cuenta está desactivada. Contacta a Recursos Humanos.']);

    $this->assertGuest('web');
});

test('people:crear-admin crea una cuenta super_admin sin colaborador que puede entrar', function () {
    $this->artisan('people:crear-admin', [
        '--nombre' => 'Jesus',
        '--apellidos' => 'Arizmendi',
        '--correo' => 'arranque@mrlana.mx',
        '--password' => 'secreta123',
        '--force' => true,
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'arranque@mrlana.mx')->firstOrFail();

    expect($admin->colaborador_id)->toBeNull();
    expect($admin->hasRole('super_admin'))->toBeTrue();

    $this->post(route('login.store'), ['username' => $admin->username, 'password' => 'secreta123'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($admin);
});

test('people:crear-admin es idempotente: reusa la cuenta por correo y actualiza su contraseña', function () {
    $this->artisan('people:crear-admin', [
        '--nombre' => 'Jesus', '--apellidos' => 'Arizmendi',
        '--correo' => 'arranque@mrlana.mx', '--password' => 'secreta123', '--force' => true,
    ])->assertSuccessful();

    $this->artisan('people:crear-admin', [
        '--nombre' => 'Jesus', '--apellidos' => 'Arizmendi',
        '--correo' => 'arranque@mrlana.mx', '--password' => 'nueva-clave-456', '--force' => true,
    ])->assertSuccessful();

    expect(User::query()->where('email', 'arranque@mrlana.mx')->count())->toBe(1);

    $admin = User::query()->where('email', 'arranque@mrlana.mx')->firstOrFail();
    expect(Hash::check('nueva-clave-456', $admin->password))->toBeTrue();
});

test('people:reset-produccion no borra nada si la frase de confirmación es incorrecta', function () {
    $rolesAntes = Role::query()->count();

    $this->artisan('people:reset-produccion')
        ->expectsQuestion('Para confirmar, escribe exactamente «BORRAR TODO»', 'no')
        ->assertFailed();

    expect(Role::query()->count())->toBe($rolesAntes);
});
