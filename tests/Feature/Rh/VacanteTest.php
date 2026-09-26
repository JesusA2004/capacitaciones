<?php

use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

// Las pruebas de crear/cancelar/eliminar vacantes a mano se retiraron: esas
// rutas ya no existen porque las vacantes se abren y cierran solas desde
// headcount (docs/HEADCOUNT_Y_VACANTES.md, VacanteAutoGenerationService).

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

    $gerente = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['sucursal_principal_id' => $sucursalPropia->id])->id]);
    $gerente->assignRole('gerente_sucursal');

    $respuesta = $this->actingAs($gerente)->get(route('rh.vacantes.index'));

    $respuesta->assertOk();
    $vacantes = $respuesta->viewData('page')['props']['vacantes'];

    expect($vacantes)->toHaveCount(1)
        ->and($vacantes[0]['sucursal_id'])->toBe($sucursalPropia->id);
});

test('el listado de vacantes anota plantilla autorizada actual y faltantes reales', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();

    HeadcountTarget::factory()->create([
        'sucursal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'plantilla_autorizada' => 5,
    ]);
    Colaborador::factory()->count(2)->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'estatus' => 'activo',
    ]);

    Vacante::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id]);

    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $respuesta = $this->actingAs($usuario)->get(route('rh.vacantes.index'));
    $vacante = $respuesta->viewData('page')['props']['vacantes'][0];

    expect($vacante['plantilla_autorizada'])->toBe(5)
        ->and($vacante['plantilla_actual'])->toBe(2)
        ->and($vacante['faltantes_reales'])->toBe(3);
});
