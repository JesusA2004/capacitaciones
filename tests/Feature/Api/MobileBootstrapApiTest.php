<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function bootstrapHeaders(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('un colaborador recibe capabilities correctas en el bootstrap', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $respuesta = $this->withHeaders(bootstrapHeaders($colaborador))
        ->getJson('/api/v1/mobile/bootstrap')
        ->assertOk()
        ->assertJsonStructure(['user', 'capabilities', 'features', 'counts', 'server']);

    expect($respuesta->json('capabilities.employee'))->toBeTrue()
        ->and($respuesta->json('capabilities.rh'))->toBeFalse()
        ->and($respuesta->json('capabilities.manager'))->toBeFalse()
        ->and($respuesta->json('capabilities.director'))->toBeFalse();
});

test('un usuario de rh recibe rh=true en el bootstrap', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $respuesta = $this->withHeaders(bootstrapHeaders($rh))
        ->getJson('/api/v1/mobile/bootstrap')
        ->assertOk();

    expect($respuesta->json('capabilities.rh'))->toBeTrue()
        ->and($respuesta->json('features.rh_mobile'))->toBeTrue();
});

test('un colaborador sin permisos rh no ve capabilities indebidas', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $respuesta = $this->withHeaders(bootstrapHeaders($colaborador))
        ->getJson('/api/v1/mobile/bootstrap')
        ->assertOk();

    expect($respuesta->json('counts.rh_pendientes'))->toBe(0)
        ->and($respuesta->json('user.permissions'))->not->toContain('rh.pendientes.ver');
});

test('el bootstrap no expone rutas del disco nas', function () {
    $colaborador = User::factory()->create(['foto_path' => 'expedientes/1/foto/foto.jpg']);
    $colaborador->assignRole('colaborador');

    $respuesta = $this->withHeaders(bootstrapHeaders($colaborador))
        ->getJson('/api/v1/mobile/bootstrap')
        ->assertOk();

    $crudo = $respuesta->getContent();

    expect($crudo)->not->toContain('expedientes/1/foto/foto.jpg')
        ->and($crudo)->not->toContain('/mnt/people-storage');
});

test('app config es publico y trae los flags esperados', function () {
    $this->getJson('/api/v1/app/config')
        ->assertOk()
        ->assertJsonStructure(['maintenance', 'minimum_version', 'latest_version', 'force_update', 'message', 'features']);
});

test('una cuenta administrativa sin expediente de colaborador no recibe Mi espacio', function () {
    $admin = User::factory()->create(['colaborador_id' => null]);
    $admin->assignRole('rh_admin');

    $respuesta = $this->withHeaders(bootstrapHeaders($admin))
        ->getJson('/api/v1/mobile/bootstrap')
        ->assertOk();

    expect($respuesta->json('capabilities.employee'))->toBeFalse()
        ->and($respuesta->json('capabilities.rh'))->toBeTrue();
});

test('un colaborador que tambien tiene permisos de rh recibe ambas experiencias', function () {
    $ambos = User::factory()->create();
    $ambos->assignRole('rh_admin');

    $respuesta = $this->withHeaders(bootstrapHeaders($ambos))
        ->getJson('/api/v1/mobile/bootstrap')
        ->assertOk();

    expect($respuesta->json('capabilities.employee'))->toBeTrue()
        ->and($respuesta->json('capabilities.rh'))->toBeTrue();
});
