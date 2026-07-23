<?php

use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un super_admin puede crear una empresa', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->post(route('administracion.empresas.store'), [
            'nombre' => 'Grupo Lana',
            'razon_social' => 'Grupo Lana S.A. de C.V.',
            'rfc' => 'GLA850101ABC',
            'activo' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(Empresa::where('nombre', 'Grupo Lana')->exists())->toBeTrue();
});

test('rh_admin puede crear y editar empresas pero no eliminarlas', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('administracion.empresas.store'), ['nombre' => 'Empresa RH', 'activo' => true])
        ->assertSessionHasNoErrors();

    $empresa = Empresa::where('nombre', 'Empresa RH')->firstOrFail();

    $this->actingAs($usuario)
        ->delete(route('administracion.empresas.destroy', $empresa))
        ->assertForbidden();
});

test('un colaborador no puede administrar empresas', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('administracion.empresas.index'))
        ->assertForbidden();
});

test('un super_admin puede eliminar una empresa sin sucursales', function () {
    $empresa = Empresa::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->delete(route('administracion.empresas.destroy', $empresa))
        ->assertRedirect();

    expect(Empresa::find($empresa->id))->toBeNull();
});

test('no se puede eliminar una empresa con sucursales asociadas', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)->delete(route('administracion.empresas.destroy', $empresa));

    expect(Empresa::find($empresa->id))->not->toBeNull();
});
