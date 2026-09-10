<?php

use App\Models\User;
use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(BirthdayPhraseSeeder::class);
    Storage::fake('nas');
});

function headersBearerCumpleanos(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('la felicitacion actual funciona con bearer cuando hoy es el cumpleanos del colaborador', function () {
    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->subYears(29)]);
    $colaborador->assignRole('colaborador');

    $respuesta = $this->withHeaders(headersBearerCumpleanos($colaborador))
        ->getJson('/api/v1/colaborador/cumpleanos/felicitacion-actual')
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'fecha', 'titulo', 'mensaje', 'frase', 'card_url']]);

    expect($respuesta->json('data.titulo'))->toBe('¡Feliz cumpleaños!');
});

test('la felicitacion actual regresa 200 con data null cuando hoy no es el cumpleanos del colaborador', function () {
    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->addDays(10)->subYears(29)]);
    $colaborador->assignRole('colaborador');

    $respuesta = $this->withHeaders(headersBearerCumpleanos($colaborador))
        ->getJson('/api/v1/colaborador/cumpleanos/felicitacion-actual')
        ->assertOk();

    expect($respuesta->json('data'))->toBeNull();
});

test('la api del colaborador nunca regresa la felicitacion de otro colaborador', function () {
    $hoy = User::factory()->create(['fecha_nacimiento' => now()->subYears(29)]);
    $otro = User::factory()->create(['fecha_nacimiento' => now()->addDays(10)->subYears(29)]);
    $otro->assignRole('colaborador');

    $respuesta = $this->withHeaders(headersBearerCumpleanos($otro))
        ->getJson('/api/v1/colaborador/cumpleanos/felicitacion-actual')
        ->assertOk();

    expect($respuesta->json('data'))->toBeNull();
});
