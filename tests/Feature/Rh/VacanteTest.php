<?php

use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh_admin puede crear una vacante', function () {
    $puesto = Puesto::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.vacantes.store'), [
            'puesto_id' => $puesto->id,
            'motivo' => 'nueva_posicion',
            'fecha_apertura' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    expect(Vacante::where('puesto_id', $puesto->id)->exists())->toBeTrue();
});

test('una vacante creada a mano por rh_admin nace con una plaza disponible', function () {
    $puesto = Puesto::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.vacantes.store'), [
            'puesto_id' => $puesto->id,
            'motivo' => 'nueva_posicion',
            'fecha_apertura' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    $vacante = Vacante::where('puesto_id', $puesto->id)->firstOrFail();

    expect($vacante->generada_automaticamente)->toBeFalse()
        ->and($vacante->plazas_requeridas)->toBe(1)
        ->and($vacante->plazas_cubiertas)->toBe(0)
        ->and($vacante->plazas_disponibles)->toBe(1);
});

test('el listado de vacantes incluye los kpis del tablero', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    Vacante::factory()->create(['estado' => 'abierta', 'generada_automaticamente' => true, 'plazas_disponibles' => 2]);
    Vacante::factory()->create(['estado' => 'en_reclutamiento', 'generada_automaticamente' => false, 'plazas_disponibles' => 1]);
    Vacante::factory()->create(['estado' => 'cubierta']);
    Vacante::factory()->create(['estado' => 'cancelada']);

    $respuesta = $this->actingAs($usuario)->get(route('rh.vacantes.index'));

    $respuesta->assertOk();
    $kpis = $respuesta->viewData('page')['props']['kpis'];

    expect($kpis['vacantes_abiertas'])->toBe(2)
        ->and($kpis['plazas_disponibles'])->toBe(3)
        ->and($kpis['vacantes_automaticas'])->toBe(1)
        ->and($kpis['vacantes_manuales'])->toBe(1)
        ->and($kpis['en_reclutamiento'])->toBe(1)
        ->and($kpis['canceladas'])->toBe(1);
});

test('un colaborador no puede ver vacantes', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('rh.vacantes.index'))
        ->assertForbidden();
});

test('un gerente_sucursal solo ve vacantes de su sucursal', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();

    Vacante::factory()->create(['sucursal_id' => $sucursalPropia->id]);
    Vacante::factory()->create(['sucursal_id' => $sucursalAjena->id]);

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $respuesta = $this->actingAs($gerente)->get(route('rh.vacantes.index'));

    $respuesta->assertOk();
    $vacantes = $respuesta->viewData('page')['props']['vacantes'];

    expect($vacantes)->toHaveCount(1)
        ->and($vacantes[0]['sucursal_id'])->toBe($sucursalPropia->id);
});

test('rh_auxiliar no puede cerrar una vacante', function () {
    $vacante = Vacante::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $this->actingAs($usuario)
        ->put(route('rh.vacantes.estado', $vacante), ['estado' => 'cubierta'])
        ->assertForbidden();
});
