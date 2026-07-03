<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function usuarioConRol(string $rol): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    return $usuario;
}

test('un super_admin puede ver el listado de roles', function () {
    $usuario = usuarioConRol('super_admin');

    $this->actingAs($usuario)
        ->get(route('administracion.roles.index'))
        ->assertOk();
});

test('un colaborador no puede ver el listado de roles', function () {
    $usuario = usuarioConRol('colaborador');

    $this->actingAs($usuario)
        ->get(route('administracion.roles.index'))
        ->assertForbidden();
});

test('un super_admin puede crear un rol con permisos', function () {
    $usuario = usuarioConRol('super_admin');

    $this->actingAs($usuario)
        ->post(route('administracion.roles.store'), [
            'nombre' => 'coordinador_regional',
            'permisos' => ['reportes.sucursal', 'asistencias.ver'],
        ])
        ->assertSessionHasNoErrors();

    $rol = Role::where('name', 'coordinador_regional')->first();

    expect($rol)->not->toBeNull();
    expect($rol->permissions->pluck('name')->all())->toEqualCanonicalizing(['reportes.sucursal', 'asistencias.ver']);
});

test('no se puede eliminar el rol protegido super_admin', function () {
    $usuario = usuarioConRol('super_admin');
    $rolProtegido = Role::where('name', 'super_admin')->first();

    $this->actingAs($usuario)
        ->delete(route('administracion.roles.destroy', $rolProtegido))
        ->assertForbidden();

    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
});

test('un super_admin puede clonar un rol existente', function () {
    $usuario = usuarioConRol('super_admin');
    $rolOriginal = Role::where('name', 'supervisor')->first();

    $this->actingAs($usuario)
        ->post(route('administracion.roles.clonar', $rolOriginal), [
            'nombre' => 'supervisor_norte',
        ])
        ->assertSessionHasNoErrors();

    $clon = Role::where('name', 'supervisor_norte')->first();

    expect($clon)->not->toBeNull();
    expect($clon->permissions->pluck('name')->all())->toEqualCanonicalizing($rolOriginal->permissions->pluck('name')->all());
});
