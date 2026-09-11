<?php

use App\Enums\Genero;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(SucursalSeeder::class);
});

test('rh_admin puede consultar los KPIs de rotación en vivo', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = User::factory()->create(['genero' => Genero::Femenino]);
    app(MovimientoLaboralService::class)->registrarAlta($colaborador, $rh);

    $respuesta = $this->actingAs($rh)->getJson(route('dashboard.rotacion'));

    $respuesta->assertOk()
        ->assertJsonStructure([
            'periodo' => ['desde', 'hasta'],
            'plantilla_actual',
            'altas',
            'bajas',
            'rotacion_porcentaje',
            'eficiencia',
            'genero',
            'altasPorSucursal',
            'bajasPorSucursal',
            'tendenciaMensual',
        ]);
});

test('el filtro de sucursal acota altas/bajas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $sucursal = Sucursal::first();
    $colaborador = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    app(MovimientoLaboralService::class)->registrarAlta($colaborador, $rh);

    $otraSucursalId = Sucursal::where('id', '!=', $sucursal->id)->value('id');

    $respuesta = $this->actingAs($rh)->getJson(
        route('dashboard.rotacion', ['sucursal_id' => $otraSucursalId]),
    );

    $respuesta->assertOk()->assertJsonPath('altas', 0);
});

test('un colaborador sin permiso operativo no puede consultar el KPI de rotación', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->getJson(route('dashboard.rotacion'))
        ->assertForbidden();
});

test('la composición por género no cuenta dos veces a "sin especificar"', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    User::factory()->create(['genero' => null, 'estatus' => 'activo']);

    $respuesta = $this->actingAs($rh)->getJson(route('dashboard.rotacion'));

    $sinEspecificar = collect($respuesta->json('genero'))
        ->firstWhere('etiqueta', 'Sin especificar');

    $total = collect($respuesta->json('genero'))->sum('valor');

    expect($total)->toBe($respuesta->json('plantilla_actual'))
        ->and($sinEspecificar['valor'])->toBeGreaterThanOrEqual(1);
});
