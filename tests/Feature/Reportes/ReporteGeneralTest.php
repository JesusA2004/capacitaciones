<?php

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Reportes\ReportesRhService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

it('carga el hub de reportes y ambos exportes para un usuario con permiso', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)->get('/reportes')->assertOk();
    // Las exportaciones viven solo en /rh/reportes (las rutas duplicadas
    // /reportes/exportar/* se retiraron: nada las usaba).
    $this->actingAs($usuario)->get(route('rh.reportes.excel'))->assertOk();
    $this->actingAs($usuario)->get(route('rh.reportes.pdf'))->assertOk();
    $this->actingAs($usuario)->get('/reportes/exportar/excel')->assertNotFound();
});

it('bloquea el hub de reportes sin permiso', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)->get('/reportes')->assertForbidden();
});

it('reporta hombres y mujeres de la plantilla activa, en general y por sucursal', function () {
    $centro = Sucursal::factory()->create(['nombre' => 'Centro', 'activo' => true]);
    $norte = Sucursal::factory()->create(['nombre' => 'Norte', 'activo' => true]);
    Colaborador::factory()->count(2)->create(['genero' => 'masculino', 'estatus' => 'activo', 'sucursal_principal_id' => $centro->id]);
    Colaborador::factory()->create(['genero' => 'femenino', 'estatus' => 'activo', 'sucursal_principal_id' => $centro->id]);
    Colaborador::factory()->create(['genero' => 'femenino', 'estatus' => 'activo', 'sucursal_principal_id' => $norte->id]);
    Colaborador::factory()->create(['genero' => 'masculino', 'estatus' => 'inactivo', 'sucursal_principal_id' => $norte->id]);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $servicio = app(ReportesRhService::class);

    $general = $servicio->generar('empleados_por_genero', $rh, ['sucursal_id' => $centro->id]);
    expect($general['filas'])->toBe([['Hombres', 2], ['Mujeres', 1], ['Sin género capturado', 0], ['Total', 3]]);

    $porSucursal = collect($servicio->generar('genero_por_sucursal', $rh, [])['filas'])->keyBy(0);
    expect($porSucursal['Centro'])->toBe(['Centro', 2, 1, 0, 3])
        ->and($porSucursal['Norte'])->toBe(['Norte', 0, 1, 0, 1]);

    $this->actingAs($rh)->get('/reportes?reporte=genero_por_sucursal')->assertOk();
});
