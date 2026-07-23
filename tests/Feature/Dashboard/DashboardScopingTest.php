<?php

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

test('el desglose de colaboradores por sucursal del dashboard global incluye todas las sucursales', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $sucursalA = Sucursal::factory()->create(['nombre' => 'Sucursal A']);
    $sucursalB = Sucursal::factory()->create(['nombre' => 'Sucursal B']);

    User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $etiquetas = collect($page->toArray()['props']['graficas']['colaboradoresPorSucursal'])->pluck('etiqueta');

        expect($etiquetas)->toContain('Sucursal A');
        expect($etiquetas)->toContain('Sucursal B');
    });
});

test('un gerente de sucursal solo ve colaboradores de su propia sucursal en el dashboard', function () {
    $sucursalPropia = Sucursal::factory()->create(['nombre' => 'Mi sucursal']);
    $sucursalAjena = Sucursal::factory()->create(['nombre' => 'Otra sucursal']);

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $respuesta = $this->actingAs($gerente)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $etiquetas = collect($page->toArray()['props']['graficas']['colaboradoresPorSucursal'])->pluck('etiqueta');

        expect($etiquetas)->not->toContain('Otra sucursal');
    });
});
