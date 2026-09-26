<?php

use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // "Hoy" es America/Mexico_City: reloj fijo a mediodía en México para que
    // las fechas armadas con now() no fallen de noche (en UTC ya es mañana).
    Carbon::setTestNow(Carbon::parse('2026-09-25 18:00:00', 'UTC'));
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(BirthdayPhraseSeeder::class);
    Storage::fake('nas');
});

afterEach(fn () => Carbon::setTestNow());

function headersBearerCumpleanos(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('la felicitacion actual funciona con bearer cuando hoy es el cumpleanos del colaborador', function () {
    $colaborador = User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->subYears(29)]), 'colaborador')->create();
    $colaborador->assignRole('colaborador');

    $respuesta = $this->withHeaders(headersBearerCumpleanos($colaborador))
        ->getJson('/api/v1/colaborador/cumpleanos/felicitacion-actual')
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'fecha', 'titulo', 'mensaje', 'frase', 'card_url']]);

    expect($respuesta->json('data.titulo'))->toBe('¡Feliz cumpleaños!');
});

test('la felicitacion actual regresa 200 con data null cuando hoy no es el cumpleanos del colaborador', function () {
    $colaborador = User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->addDays(10)->subYears(29)]), 'colaborador')->create();
    $colaborador->assignRole('colaborador');

    $respuesta = $this->withHeaders(headersBearerCumpleanos($colaborador))
        ->getJson('/api/v1/colaborador/cumpleanos/felicitacion-actual')
        ->assertOk();

    expect($respuesta->json('data'))->toBeNull();
});

test('la api del colaborador nunca regresa la felicitacion de otro colaborador', function () {
    $hoy = User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->subYears(29)]), 'colaborador')->create();
    $otro = User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->addDays(10)->subYears(29)]), 'colaborador')->create();
    $otro->assignRole('colaborador');

    $respuesta = $this->withHeaders(headersBearerCumpleanos($otro))
        ->getJson('/api/v1/colaborador/cumpleanos/felicitacion-actual')
        ->assertOk();

    expect($respuesta->json('data'))->toBeNull();
});
