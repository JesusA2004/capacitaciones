<?php

use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function actuarConToken(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('un colaborador puede ver su propio perfil basico por la api', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(2)]);
    $colaborador->assignRole('colaborador');

    $this->withHeaders(actuarConToken($colaborador))
        ->getJson('/api/v1/colaborador/perfil')
        ->assertOk()
        ->assertJsonPath('nombre_completo', $colaborador->nombreCompleto())
        ->assertJsonPath('antiguedad_anios', 2);
});

test('el dashboard del colaborador trae vacaciones, solicitudes recientes y notificaciones', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(1)]);
    $colaborador->assignRole('colaborador');
    SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);

    $this->withHeaders(actuarConToken($colaborador))
        ->getJson('/api/v1/colaborador/dashboard')
        ->assertOk()
        ->assertJsonStructure(['perfil', 'vacaciones', 'solicitudes_recientes', 'notificaciones']);
});

test('un colaborador puede crear una solicitud interna desde la api', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(actuarConToken($colaborador))
        ->postJson('/api/v1/colaborador/solicitudes', [
            'tipo' => 'constancia_laboral',
            'motivo' => 'Trámite bancario.',
        ])
        ->assertCreated()
        ->assertJsonPath('estado', 'enviada');
});

test('el listado de solicitudes de la api solo trae las del colaborador autenticado', function () {
    $colaborador = User::factory()->create();
    $otro = User::factory()->create();
    SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);
    SolicitudInterna::factory()->create(['user_id' => $otro->id]);

    $respuesta = $this->withHeaders(actuarConToken($colaborador))
        ->getJson('/api/v1/solicitudes')
        ->assertOk();

    expect($respuesta->json('meta.total'))->toBe(1);
});

test('un colaborador no puede ver por la api la solicitud de otro colaborador', function () {
    $colaborador = User::factory()->create();
    $otro = User::factory()->create();
    $solicitudAjena = SolicitudInterna::factory()->create(['user_id' => $otro->id]);

    $this->withHeaders(actuarConToken($colaborador))
        ->getJson("/api/v1/solicitudes/{$solicitudAjena->id}")
        ->assertForbidden();
});

test('el saldo de vacaciones de la api coincide con el del colaborador autenticado', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(3)]);

    $respuesta = $this->withHeaders(actuarConToken($colaborador))
        ->getJson('/api/v1/vacaciones/saldo')
        ->assertOk();

    expect($respuesta->json('antiguedad_anios'))->toBe(3);
});
