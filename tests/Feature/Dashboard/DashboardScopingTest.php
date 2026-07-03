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

test('un colaborador ve el dashboard de colaborador', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Colaborador'));
});

test('un gerente de sucursal ve el dashboard de sucursal', function () {
    $gerente = User::factory()->create();
    $gerente->assignRole('gerente_sucursal');

    $this->actingAs($gerente)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Sucursal'));
});

test('un administrador de capacitacion ve el dashboard global', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Global'));
});

test('el cumplimiento por sucursal del dashboard global incluye todas las sucursales', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $sucursalA = Sucursal::factory()->create(['nombre' => 'Sucursal A']);
    $sucursalB = Sucursal::factory()->create(['nombre' => 'Sucursal B']);

    $colaboradorA = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $colaboradorB = User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $responsable = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso de prueba', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $responsable->id]);

    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaboradorA->id, 'estado' => 'completada']);
    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaboradorB->id, 'estado' => 'pendiente']);

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) use ($sucursalA, $sucursalB) {
        $filas = collect($page->toArray()['props']['cumplimientoPorSucursal']);

        expect($filas->firstWhere('sucursal_id', $sucursalA->id)['porcentaje'])->toEqual(100);
        expect($filas->firstWhere('sucursal_id', $sucursalB->id)['porcentaje'])->toEqual(0);
    });
});

test('un gerente de sucursal solo ve el cumplimiento de su propia sucursal', function () {
    $sucursalPropia = Sucursal::factory()->create(['nombre' => 'Mi sucursal']);
    $sucursalAjena = Sucursal::factory()->create(['nombre' => 'Otra sucursal']);

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);
    $responsable = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso de prueba', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $responsable->id]);
    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaboradorAjeno->id, 'estado' => 'completada']);

    $respuesta = $this->actingAs($gerente)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) use ($sucursalAjena) {
        $filas = collect($page->toArray()['props']['cumplimientoPorSucursal']);

        expect($filas->firstWhere('sucursal_id', $sucursalAjena->id))->toBeNull();
    });
});
