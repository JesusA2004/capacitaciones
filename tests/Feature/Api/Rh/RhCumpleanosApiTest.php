<?php

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Services\Cumpleanos\BirthdayCardService;
use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(BirthdayPhraseSeeder::class);
    Storage::fake('nas');
});

function headersBearerRhCumpleanos(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('rh ve la bandeja de cumpleanos de hoy desde la app con el permiso correcto', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $cumpleaniero = User::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);
    User::factory()->create(['fecha_nacimiento' => now()->addDays(20)->subYears(30)]);

    $respuesta = $this->withHeaders(headersBearerRhCumpleanos($rh))
        ->getJson('/api/v1/rh/cumpleanos?periodo=hoy')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['greeting_id', 'colaborador' => ['id', 'nombre', 'numero_empleado', 'foto_url_api', 'puesto', 'sucursal', 'departamento'], 'dia', 'es_hoy', 'felicitacion_generada', 'enviada']],
            'meta' => ['hoy', 'proximos_7_dias', 'proximos_30_dias', 'current_page', 'total'],
        ]);

    $ids = collect($respuesta->json('data'))->pluck('colaborador.id');
    expect($ids)->toContain($cumpleaniero->id);
});

test('un colaborador sin el permiso rh.cumpleanos.ver no puede consultar la bandeja de rh', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(headersBearerRhCumpleanos($colaborador))
        ->getJson('/api/v1/rh/cumpleanos')
        ->assertForbidden();
});

test('el detalle de una felicitacion por greeting_id funciona para abrir un push rh_cumpleanos', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);
    $greeting = app(BirthdayCardService::class)->generar($colaborador, now());

    $this->withHeaders(headersBearerRhCumpleanos($rh))
        ->getJson("/api/v1/rh/cumpleanos/{$greeting->id}")
        ->assertOk()
        ->assertJsonPath('data.greeting_id', $greeting->id);
});

test('las respuestas de la bandeja rh no incluyen el anio de nacimiento', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    User::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);

    $respuesta = $this->withHeaders(headersBearerRhCumpleanos($rh))
        ->getJson('/api/v1/rh/cumpleanos?periodo=hoy')
        ->assertOk();

    $json = json_encode($respuesta->json());
    expect($json)->not->toContain('fecha_nacimiento');
});
