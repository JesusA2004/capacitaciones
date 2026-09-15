<?php

use App\Models\User;
use App\Services\Navigation\NavigationService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('super_admin puro entra en modo operativo y no tiene modo colaborador disponible', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    // super_admin es un rol operativo: RolesYPermisosSeeder::PERMISOS ya NO
    // incluye los permisos personales de "modo colaborador" (portal.*), así
    // que un super_admin puro no ve "Mi portal" ni por sidebar ni por URL
    // directa (sección 25 del cierre) — a diferencia de antes, donde
    // heredaba portal.ver solo por tener todos los permisos en bloque.
    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('navegacion.modoActual', 'operativo')
            ->where('navegacion.modosDisponibles', ['operativo'])
        );

    $this->actingAs($usuario)->get(route('portal.index'))->assertForbidden();
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

    // Una vez con la cookie puesta, modoActual() respeta el modo elegido
    // (probado a nivel de servicio: el round-trip de cookies entre
    // peticiones de prueba no siempre refleja el comportamiento real de un
    // navegador, ver docs/PRUEBAS_MANUALES.md).
    $servicio = app(NavigationService::class);
    expect($servicio->modoActual($usuario, 'colaborador'))->toBe('colaborador');
});

test('un usuario sin ningún permiso de navegación recibe 403 en vez de un modo inventado', function () {
    // Cuenta mal configurada (sin rol o con un rol sin permisos de
    // navegación): NavigationService::modosDisponibles() ya no inventa
    // ['colaborador'] por defecto, así que el dashboard debe rechazar
    // explícitamente en vez de mostrar una vista de colaborador falsa.
    $usuario = User::factory()->create();

    $this->actingAs($usuario)->get(route('dashboard'))->assertForbidden();
});

test('cambiar a un modo no disponible responde 403', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('modo-navegacion.update'), ['modo' => 'operativo'])
        ->assertForbidden();
});
