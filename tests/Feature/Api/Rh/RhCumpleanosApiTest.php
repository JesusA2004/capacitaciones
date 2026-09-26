<?php

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Cumpleanos\BirthdayCardService;
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

function headersBearerRhCumpleanos(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('rh ve la bandeja de cumpleanos de hoy desde la app con el permiso correcto', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $cumpleaniero = User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->subYears(30)]), 'colaborador')->create();
    User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->addDays(20)->subYears(30)]), 'colaborador')->create();

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

    $colaborador = User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->subYears(30)]), 'colaborador')->create();
    $greeting = app(BirthdayCardService::class)->generar($colaborador->colaborador, now());

    $this->withHeaders(headersBearerRhCumpleanos($rh))
        ->getJson("/api/v1/rh/cumpleanos/{$greeting->id}")
        ->assertOk()
        ->assertJsonPath('data.greeting_id', $greeting->id);
});

test('las respuestas de la bandeja rh no incluyen el anio de nacimiento', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    User::factory()->for(Colaborador::factory()->state(['fecha_nacimiento' => now()->subYears(30)]), 'colaborador')->create();

    $respuesta = $this->withHeaders(headersBearerRhCumpleanos($rh))
        ->getJson('/api/v1/rh/cumpleanos?periodo=hoy')
        ->assertOk();

    $json = json_encode($respuesta->json());
    expect($json)->not->toContain('fecha_nacimiento');
});
