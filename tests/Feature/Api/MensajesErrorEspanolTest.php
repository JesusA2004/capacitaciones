<?php

use App\Support\MensajesErrorApi;
use Database\Seeders\RolesYPermisosSeeder;
use Laravel\Sanctum\Sanctum;

test('la api responde el 401 genérico en español', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', MensajesErrorApi::traducir(401, 'Unauthenticated.'));
});

test('un registro inexistente responde 404 en español sin exponer el modelo', function () {
    $this->seed(RolesYPermisosSeeder::class);
    Sanctum::actingAs(clUsuario('colaborador'));

    $mensaje = $this->getJson('/api/v1/solicitudes/999999')->assertNotFound()->json('message');

    expect($mensaje)->toBe(MensajesErrorApi::traducir(404, 'Not Found'))
        ->and($mensaje)->not->toContain('App\\Models');
});

test('el 403 genérico de una policy sale en español', function () {
    $this->seed(RolesYPermisosSeeder::class);
    Sanctum::actingAs(clUsuario('colaborador'));

    $mensaje = $this->getJson('/api/v1/rh/solicitudes')->assertForbidden()->json('message');

    expect($mensaje)->not->toBe('This action is unauthorized.')
        ->and($mensaje)->toContain('permiso');
});

test('un mensaje propio nunca se reemplaza', function () {
    expect(MensajesErrorApi::traducir(403, 'Tu cuenta no está vinculada a un expediente.'))->toBeNull()
        ->and(MensajesErrorApi::traducir(409, 'El formato todavía no se libera.'))->toBeNull()
        ->and(MensajesErrorApi::traducir(429, 'Too Many Attempts.'))->toContain('demasiados intentos');
});
