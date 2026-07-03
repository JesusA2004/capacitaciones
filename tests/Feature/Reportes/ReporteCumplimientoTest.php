<?php

use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\Curso;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador sin permiso de reportes no puede ver el reporte de cumplimiento', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->get(route('reportes.cumplimiento.index'))
        ->assertForbidden();
});

test('un gerente de sucursal solo ve colaboradores de su propia sucursal en el reporte', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorPropio = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $respuesta = $this->actingAs($gerente)->get(route('reportes.cumplimiento.index'))->assertOk();

    $respuesta->assertInertia(function ($page) use ($colaboradorPropio, $colaboradorAjeno) {
        $ids = collect($page->toArray()['props']['colaboradores']['data'])->pluck('id');

        expect($ids)->toContain($colaboradorPropio->id);
        expect($ids)->not->toContain($colaboradorAjeno->id);
    });
});

test('el filtro por sucursal limita el reporte a esa sucursal', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();
    $colaboradorA = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $colaboradorB = User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $respuesta = $this->actingAs($admin)
        ->get(route('reportes.cumplimiento.index', ['sucursal_id' => $sucursalA->id]))
        ->assertOk();

    $respuesta->assertInertia(function ($page) use ($colaboradorA, $colaboradorB) {
        $ids = collect($page->toArray()['props']['colaboradores']['data'])->pluck('id');

        expect($ids)->toContain($colaboradorA->id);
        expect($ids)->not->toContain($colaboradorB->id);
    });
});

test('un usuario sin permiso de exportar no puede descargar el reporte', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');

    $this->actingAs($supervisor)
        ->get(route('reportes.cumplimiento.exportar'))
        ->assertForbidden();
});

test('un usuario con permiso de exportar descarga un archivo de Excel', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $colaborador = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso de prueba', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $admin->id]);
    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaborador->id, 'estado' => 'completada']);

    $respuesta = $this->actingAs($admin)->get(route('reportes.cumplimiento.exportar'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-disposition'))->toContain('reporte-cumplimiento-');
});
