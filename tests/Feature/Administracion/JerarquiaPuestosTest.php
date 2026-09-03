<?php

use App\Models\Puesto;
use App\Models\User;
use App\Models\Vacante;
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

test('no se permite un ciclo jerárquico de varios niveles', function () {
    // A -> B -> C ya existente; intentar que A dependa de C cerraría el ciclo.
    $a = Puesto::factory()->create(['nombre' => 'A']);
    $b = Puesto::factory()->create(['nombre' => 'B', 'puesto_superior_id' => $a->id]);
    $c = Puesto::factory()->create(['nombre' => 'C', 'puesto_superior_id' => $b->id]);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->put(route('administracion.jerarquia-puestos.actualizar', $a), [
            'puesto_superior_id' => $c->id,
        ])
        ->assertSessionHasErrors('puesto_superior_id');

    expect($a->fresh()->puesto_superior_id)->toBeNull();
});

test('no se permite un ciclo en la ruta de crecimiento', function () {
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    $subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'puesto_crecimiento_id' => $gestor->id]);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->put(route('administracion.jerarquia-puestos.actualizar', $gestor), [
            'puesto_crecimiento_id' => $subgerente->id,
        ])
        ->assertSessionHasErrors('puesto_crecimiento_id');
});

test('se puede editar en un mismo request los puestos que un puesto puede cubrir', function () {
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente']);
    $subgerente = Puesto::factory()->create(['nombre' => 'Subgerente']);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->put(route('administracion.jerarquia-puestos.actualizar', $subgerente), [
            'requiere_ruta' => false,
            'puestos_que_puede_cubrir' => [$gerente->id],
        ])
        ->assertSessionHasNoErrors();

    expect($subgerente->fresh()->puestosQuePuedeCubrir()->pluck('puestos.id')->all())->toBe([$gerente->id])
        ->and($gerente->fresh()->respaldos()->pluck('puestos.id')->all())->toBe([$subgerente->id]);
});

test('el historial de un puesto expone cambios de jerarquía, movimientos y vacantes', function () {
    $puesto = Puesto::factory()->create(['nombre' => 'Gerente']);

    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)
        ->put(route('administracion.jerarquia-puestos.actualizar', $puesto), [
            'nivel_jerarquico' => 2,
        ]);

    Vacante::factory()->create(['puesto_id' => $puesto->id]);

    $respuesta = $this->actingAs($usuario)
        ->getJson(route('administracion.jerarquia-puestos.historial', $puesto))
        ->assertOk()
        ->json();

    expect($respuesta['cambiosJerarquia'])->not->toBeEmpty()
        ->and($respuesta['vacantes'])->toHaveCount(1);
});
