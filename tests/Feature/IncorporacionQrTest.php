<?php

use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\User;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

/**
 * @param  array<string, mixed>  $datos
 * @return array{invitacion: \App\Models\IncorporacionInvitacion, token: string, rh: User}
 */
function crearInvitacionParaQr(array $datos = []): array
{
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $servicio = app(IncorporacionInvitacionService::class);

    return [...$servicio->crear($datos, $rh), 'rh' => $rh];
}

test('la pagina publica del qr con un token valido responde 200', function () {
    ['token' => $token] = crearInvitacionParaQr(['nombre_prellenado' => 'Ana Torres']);

    $this->get("/incorporacion/qr/{$token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Incorporacion/Qr')
            ->where('valida', true)
            ->where('token', $token)
            ->where('appLink', "mrlanapeople://incorporacion/qr/{$token}")
            ->where('nombrePrellenado', 'Ana Torres'));
});

test('la pagina publica del qr con un token invalido no da 404 ni 500', function () {
    $this->get('/incorporacion/qr/token-que-no-existe')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Incorporacion/Qr')
            ->where('valida', false)
            ->where('estado', 'invalido'));
});

test('la pagina publica del qr con un token vencido, revocado o usado tampoco da error, solo estado invalido', function () {
    ['token' => $tokenVencido] = crearInvitacionParaQr(['expires_at' => now()->subDay()->toDateTimeString()]);

    $this->get("/incorporacion/qr/{$tokenVencido}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('valida', false)->where('estado', 'vencido'));

    ['token' => $tokenRevocado, 'invitacion' => $invitacionRevocada] = crearInvitacionParaQr();
    app(IncorporacionInvitacionService::class)->revocar($invitacionRevocada);

    $this->get("/incorporacion/qr/{$tokenRevocado}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('valida', false)->where('estado', 'revocado'));
});

test('abrir la pagina publica del qr no marca la invitacion como usada', function () {
    ['token' => $token, 'invitacion' => $invitacion] = crearInvitacionParaQr();

    // Se visita varias veces, como haria alguien que escanea el QR mas de una vez.
    $this->get("/incorporacion/qr/{$token}")->assertOk();
    $this->get("/incorporacion/qr/{$token}")->assertOk();

    $invitacion->refresh();
    expect($invitacion->estado)->toBe(EstadoInvitacionIncorporacion::Activo);
    expect($invitacion->usos_count)->toBe(0);
    expect($invitacion->used_at)->toBeNull();
});

test('registrar por la api si marca la invitacion como usada', function () {
    ['token' => $token, 'invitacion' => $invitacion] = crearInvitacionParaQr();

    $this->postJson("/api/v1/incorporacion/invitaciones/{$token}/registrar", [
        'name' => 'Colaborador Uno',
        'email' => 'colaborador-qr@mrlana.test',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ])->assertCreated();

    $invitacion->refresh();
    expect($invitacion->estado)->toBe(EstadoInvitacionIncorporacion::Usado);
    expect($invitacion->usos_count)->toBe(1);
    expect($invitacion->used_at)->not->toBeNull();
});

test('el qr generado por rh apunta a la ruta publica web, no a la api', function () {
    $url = app(IncorporacionInvitacionService::class)->urlQr('token-de-prueba');

    expect($url)->toEndWith('/incorporacion/qr/token-de-prueba');
    expect($url)->not->toContain('/api/');
});
