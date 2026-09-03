<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador puede iniciar sesion en la api movil y recibir un token', function () {
    $colaborador = User::factory()->create(['email' => 'movil@mrlana.test']);
    $colaborador->assignRole('colaborador');

    $respuesta = $this->postJson('/api/v1/login', [
        'email' => 'movil@mrlana.test',
        'password' => 'password',
        'device_name' => 'iphone-de-prueba',
    ]);

    $respuesta->assertOk()->assertJsonStructure(['token', 'usuario' => ['id', 'nombre', 'correo', 'roles']]);
});

test('login falla con credenciales invalidas', function () {
    User::factory()->create(['email' => 'movil@mrlana.test']);

    $this->postJson('/api/v1/login', [
        'email' => 'movil@mrlana.test',
        'password' => 'incorrecta',
    ])->assertStatus(422);
});

test('me devuelve el usuario autenticado por token', function () {
    $colaborador = User::factory()->create();
    $token = $colaborador->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('id', $colaborador->id);
});

test('sin token no se puede acceder a rutas protegidas de la api', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

test('logout revoca el token actual', function () {
    $colaborador = User::factory()->create();
    $token = $colaborador->createToken('test');

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->postJson('/api/v1/logout')
        ->assertOk();

    expect($colaborador->tokens()->whereKey($token->accessToken->id)->exists())->toBeFalse();
});
