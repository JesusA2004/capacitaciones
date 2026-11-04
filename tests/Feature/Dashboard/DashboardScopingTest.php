<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador que entra a /dashboard cae en su unico inicio: mi portal', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->get(route('dashboard'))
        ->assertRedirect(route('portal.index'));

    $this->actingAs($colaborador)
        ->followingRedirects()
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Index'));
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
