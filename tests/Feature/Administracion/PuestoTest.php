<?php

use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un administrador de capacitacion puede crear un puesto asociado a un departamento', function () {
    $departamento = Departamento::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('administrador_capacitacion');

    $this->actingAs($usuario)
        ->post(route('administracion.puestos.store'), [
            'nombre' => 'Analista de Nómina',
            'departamento_id' => $departamento->id,
            'activo' => true,
        ])
        ->assertSessionHasNoErrors();

    $puesto = Puesto::where('nombre', 'Analista de Nómina')->first();

    expect($puesto)->not->toBeNull();
    expect($puesto->departamento_id)->toBe($departamento->id);
});

test('un colaborador no puede administrar puestos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('administracion.puestos.index'))
        ->assertForbidden();
});
