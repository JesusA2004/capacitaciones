<?php

use App\Models\Departamento;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un administrador de capacitacion puede crear un departamento', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('administrador_capacitacion');

    $this->actingAs($usuario)
        ->post(route('administracion.departamentos.store'), [
            'nombre' => 'Logística',
            'activo' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(Departamento::where('nombre', 'Logística')->exists())->toBeTrue();
});

test('un colaborador no puede administrar departamentos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->post(route('administracion.departamentos.store'), ['nombre' => 'Intento', 'activo' => true])
        ->assertForbidden();
});

test('un super_admin puede eliminar un departamento', function () {
    $departamento = Departamento::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->delete(route('administracion.departamentos.destroy', $departamento))
        ->assertRedirect();

    expect(Departamento::find($departamento->id))->toBeNull();
});
