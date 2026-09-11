<?php

use App\Models\User;
use App\Services\Navigation\NavigationService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('super_admin entra en modo operativo y no tiene modo colaborador disponible', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('navegacion.modoActual', 'operativo')
            ->where('navegacion.modosDisponibles', ['operativo'])
        );
});

test('rh_admin entra en modo operativo y no tiene modo colaborador disponible', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('navegacion.modoActual', 'operativo')
            ->where('navegacion.modosDisponibles', ['operativo'])
        );
});

test('un colaborador entra en modo colaborador y no tiene modo operativo disponible', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->get(route('portal.index'))
        ->assertInertia(fn ($page) => $page
            ->where('navegacion.modoActual', 'colaborador')
            ->where('navegacion.modosDisponibles', ['colaborador'])
        );
});

test('un usuario con permisos de ambos modos puede cambiar de modo y la cookie se respeta', function () {
    $usuario = User::factory()->create();
    $usuario->givePermissionTo(['portal.ver', 'dashboard.global.ver']);

    // Sin cookie: por defecto entra en modo operativo (es la herramienta de
    // trabajo principal), con ambos modos disponibles para elegir.
    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('navegacion.modoActual', 'operativo')
            ->where('navegacion.modosDisponibles', ['operativo', 'colaborador'])
        );

    $respuesta = $this->actingAs($usuario)
        ->post(route('modo-navegacion.update'), ['modo' => 'colaborador']);

    $respuesta->assertRedirect(route('portal.index'));
    $cookie = $respuesta->headers->getCookies();
    $nombreCookie = collect($cookie)->first(fn ($c) => $c->getName() === NavigationService::nombreCookie());
    expect($nombreCookie)->not->toBeNull();
    expect($nombreCookie->getValue())->toBe('colaborador');

    // La siguiente petición, ya con la cookie puesta, respeta el modo elegido.
    $this->actingAs($usuario)
        ->withCookie(NavigationService::nombreCookie(), 'colaborador')
        ->get(route('portal.index'))
        ->assertInertia(fn ($page) => $page->where('navegacion.modoActual', 'colaborador'));
});

test('cambiar a un modo no disponible responde 403', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('modo-navegacion.update'), ['modo' => 'operativo'])
        ->assertForbidden();
});
