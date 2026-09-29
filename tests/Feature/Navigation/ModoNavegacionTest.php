<?php

use App\Models\User;
use App\Services\Navigation\NavigationService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('super_admin sin colaborador enlazado entra en modo operativo y no tiene modo colaborador', function () {
    $usuario = User::factory()->create(['colaborador_id' => null]);
    $usuario->assignRole('super_admin');

    // Cuenta sin persona detrás: no tiene "Mi espacio". (Por HTTP ni
    // siquiera conserva sesión — EnsureCuentaActiva exige colaborador — así
    // que la capacidad se verifica a nivel de servicio/Gate.)
    $servicio = app(NavigationService::class);
    expect($servicio->modosDisponibles($usuario))->toBe(['operativo'])
        ->and($servicio->modoActual($usuario, 'colaborador'))->toBe('operativo')
        ->and($usuario->can('modo-colaborador'))->toBeFalse();

    expect($this->actingAs($usuario)->get(route('portal.index'))->isOk())->toBeFalse();
});

test('rh_admin sin colaborador enlazado entra en modo operativo y no tiene modo colaborador', function () {
    $usuario = User::factory()->create(['colaborador_id' => null]);
    $usuario->assignRole('rh_admin');

    expect(app(NavigationService::class)->modosDisponibles($usuario))->toBe(['operativo']);
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
    // En incorporación (puede iniciar sesión, pero aún no es colaborador
    // activo) y sin rol: ningún modo disponible.
    $usuario = User::factory()->create(['estatus' => 'en_incorporacion']);

    $this->actingAs($usuario)->get(route('dashboard'))->assertForbidden();
});

test('cambiar a un modo no disponible responde 403', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('modo-navegacion.update'), ['modo' => 'operativo'])
        ->assertForbidden();
});
