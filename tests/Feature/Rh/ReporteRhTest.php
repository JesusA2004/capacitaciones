<?php

use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh_admin puede ver el listado de reportes RH', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->get(route('rh.reportes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Reportes/Index')
            ->has('catalogo')
            ->has('resultado.columnas')
        );
});

test('un colaborador sin permiso de reportes_rh no puede acceder', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->get(route('rh.reportes.index'))
        ->assertForbidden();
});

test('el reporte de empleados por sucursal solo cuenta colaboradores dentro del alcance del gerente', function () {
    $gerente = User::factory()->create();
    $gerente->assignRole('gerente_sucursal');

    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();
    $gerente->update(['sucursal_principal_id' => $sucursalPropia->id]);

    User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $respuesta = $this->actingAs($gerente)
        ->get(route('rh.reportes.index', ['reporte' => 'empleados_por_sucursal']))
        ->assertOk();

    $respuesta->assertInertia(function ($page) use ($sucursalPropia, $sucursalAjena) {
        $filas = $page->toArray()['props']['resultado']['filas'];
        $etiquetas = array_column($filas, 0);

        expect($etiquetas)->toContain($sucursalPropia->nombre)
            ->and($etiquetas)->not->toContain($sucursalAjena->nombre);
    });
});

test('un usuario sin permiso de exportar no puede descargar el excel', function () {
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    $this->actingAs($auxiliar)
        ->get(route('rh.reportes.excel', ['reporte' => 'empleados_total']))
        ->assertForbidden();
});

test('rh_admin puede exportar un reporte a excel', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->get(route('rh.reportes.excel', ['reporte' => 'empleados_total']))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
