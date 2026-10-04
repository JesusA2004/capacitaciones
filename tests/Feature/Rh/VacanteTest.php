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

test('vacantes: totales concretos por puesto y en qué sucursales, sin costos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $gestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    $cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca']);
    $cordoba = Sucursal::factory()->create(['nombre' => 'Córdoba']);

    Vacante::factory()->create(['puesto_id' => $gestor->id, 'sucursal_id' => $cuernavaca->id, 'estado' => 'abierta', 'plazas_disponibles' => 3]);
    Vacante::factory()->create(['puesto_id' => $gestor->id, 'sucursal_id' => $cordoba->id, 'estado' => 'en_reclutamiento', 'plazas_disponibles' => 1]);
    Vacante::factory()->create(['puesto_id' => $gerente->id, 'sucursal_id' => $cordoba->id, 'estado' => 'abierta', 'plazas_disponibles' => 1]);
    // Cubiertas y canceladas no cuentan.
    Vacante::factory()->create(['puesto_id' => $gerente->id, 'sucursal_id' => $cuernavaca->id, 'estado' => 'cubierta', 'plazas_disponibles' => 1]);
    Vacante::factory()->create(['puesto_id' => $gestor->id, 'sucursal_id' => $cuernavaca->id, 'estado' => 'cancelada', 'plazas_disponibles' => 2]);

    $props = $this->actingAs($usuario)->get(route('rh.vacantes.index'))->assertOk()->viewData('page')['props'];
    $resumen = $props['resumen'];

    expect($props)->not->toHaveKey('kpis')
        ->and($props['vacantes'][0])->not->toHaveKey('sueldo_mensual')
        ->and($resumen['plazas'])->toBe(5)
        ->and($resumen['sucursales'])->toBe(2)
        ->and($resumen['por_puesto'][0]['puesto'])->toBe('Gestor')
        ->and($resumen['por_puesto'][0]['plazas'])->toBe(4)
        ->and($resumen['por_puesto'][0]['sucursales'])->toBe([
            ['sucursal_id' => $cuernavaca->id, 'sucursal' => 'Cuernavaca', 'plazas' => 3],
            ['sucursal_id' => $cordoba->id, 'sucursal' => 'Córdoba', 'plazas' => 1],
        ])
        ->and($resumen['por_puesto'][1]['puesto'])->toBe('Gerente de Sucursal')
        ->and($resumen['por_puesto'][1]['plazas'])->toBe(1);
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
