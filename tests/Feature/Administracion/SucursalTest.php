<?php

use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un administrador de capacitacion puede crear una sucursal', function () {
    $empresa = Empresa::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('administrador_capacitacion');

    $this->actingAs($usuario)
        ->post(route('administracion.sucursales.store'), [
            'empresa_id' => $empresa->id,
            'nombre' => 'Sucursal Puebla',
            'clave' => 'PUE01',
            'activo' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(Sucursal::where('clave', 'PUE01')->exists())->toBeTrue();
});

test('un colaborador no puede crear sucursales', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('administracion.sucursales.index'))
        ->assertForbidden();
});

test('la clave de sucursal debe ser unica', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->create(['empresa_id' => $empresa->id, 'clave' => 'PUE01']);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->post(route('administracion.sucursales.store'), [
            'empresa_id' => $empresa->id,
            'nombre' => 'Otra sucursal',
            'clave' => 'PUE01',
            'activo' => true,
        ])
        ->assertSessionHasErrors('clave');
});

test('un super_admin puede actualizar y eliminar una sucursal', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->put(route('administracion.sucursales.update', $sucursal), [
            'empresa_id' => $empresa->id,
            'nombre' => 'Sucursal Renombrada',
            'clave' => $sucursal->clave,
            'activo' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($sucursal->fresh()->nombre)->toBe('Sucursal Renombrada');

    $this->actingAs($usuario)
        ->delete(route('administracion.sucursales.destroy', $sucursal))
        ->assertRedirect();

    expect(Sucursal::find($sucursal->id))->toBeNull();
});
