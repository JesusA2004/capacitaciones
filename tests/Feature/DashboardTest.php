<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with a valid role can visit the dashboard', function () {
    $this->seed(RolesYPermisosSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('colaborador');
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('an authenticated user with no role (no operativo, no colaborador) gets 403 instead of a fake dashboard', function () {
    // Antes, un usuario sin ningún permiso caía por defecto en la vista de
    // colaborador (App\Services\Navigation\NavigationService::modosDisponibles()
    // inventaba ['colaborador']) — sección 25 del cierre: una cuenta mal
    // configurada debe rechazarse explícitamente, no disfrazarse.
    // En incorporación y sin rol: una cuenta enlazada a un Colaborador
    // ACTIVO sí tendría "Mi espacio" aunque no tenga rol (NavigationService).
    $user = User::factory()->create(['estatus' => 'en_incorporacion']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertForbidden();
});
