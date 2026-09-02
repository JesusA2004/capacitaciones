<?php

use App\Models\Puesto;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un super_admin puede ver la jerarquía de puestos', function () {
    Puesto::factory()->create(['nombre' => 'Gerente']);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk();
});

test('un colaborador no puede ver la jerarquía de puestos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('administracion.jerarquia-puestos.index'))
        ->assertForbidden();
});

test('se puede actualizar la jerarquía de un puesto y sus respaldos', function () {
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente']);
    $subgerente = Puesto::factory()->create(['nombre' => 'Subgerente']);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->put(route('administracion.jerarquia-puestos.actualizar', $subgerente), [
            'tipo_puesto' => 'comercial',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerente->id,
            'puesto_crecimiento_id' => $gerente->id,
            'requiere_ruta' => false,
            'respaldos' => [],
        ])
        ->assertSessionHasNoErrors();

    $subgerente->refresh();

    expect($subgerente->puesto_superior_id)->toBe($gerente->id)
        ->and($subgerente->tipo_puesto->value)->toBe('comercial');

    $gerente->respaldos()->sync([$subgerente->id]);

    expect($gerente->respaldos()->pluck('puestos.id')->all())->toBe([$subgerente->id])
        ->and($subgerente->puestosQuePuedeCubrir()->pluck('puestos.id')->all())->toBe([$gerente->id]);
});

test('un puesto no puede declararse a sí mismo como su propio superior', function () {
    $puesto = Puesto::factory()->create();

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->put(route('administracion.jerarquia-puestos.actualizar', $puesto), [
            'puesto_superior_id' => $puesto->id,
        ])
        ->assertSessionHasErrors('puesto_superior_id');
});
