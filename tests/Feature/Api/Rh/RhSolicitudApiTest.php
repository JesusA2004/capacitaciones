<?php

use App\Models\MobileDevice;
use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh puede listar solicitudes pendientes', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    SolicitudInterna::factory()->count(2)->create(['estado' => 'enviada']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/solicitudes')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('rh puede ver el detalle de una solicitud con workflow y acciones_permitidas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson("/api/v1/rh/solicitudes/{$solicitud->id}")
        ->assertOk()
        ->assertJsonPath('data.acciones_permitidas', ['ver', 'aprobar', 'rechazar', 'solicitar_correccion'])
        ->assertJsonStructure(['data' => ['workflow' => ['estado', 'etapa_actual', 'progreso', 'flujo']]]);
});

test('rh puede aprobar una solicitud, se audita y se notifica al colaborador', function () {
    Http::fake();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    MobileDevice::factory()->for($colaborador, 'usuario')->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $colaborador->id, 'estado' => 'enviada']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/aprobar", ['comentario' => 'Todo en orden'])
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobada');

    expect($solicitud->fresh()->estado->value)->toBe('aprobada')
        ->and($solicitud->fresh()->historial()->count())->toBeGreaterThan(0)
        ->and($colaborador->fresh()->notifications()->count())->toBe(1);

    Http::assertSent(fn ($request) => $request->url() === config('expo.endpoint'));
});

test('rechazar una solicitud requiere motivo', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/rechazar", [])
        ->assertUnprocessable();
});

test('rechazar una solicitud con motivo la marca rechazada', function () {
    Http::fake();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/rechazar", ['motivo' => 'No procede'])
        ->assertOk()
        ->assertJsonPath('data.estado', 'rechazada');
});

test('una solicitud ya resuelta no admite volver a aprobarse', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $solicitud = SolicitudInterna::factory()->create(['estado' => 'rechazada']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/aprobar", [])
        ->assertStatus(422);
});

test('un colaborador no puede aprobar solicitudes', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/aprobar", [])
        ->assertForbidden();
});

test('crear una solicitud notifica y encola push para rh', function () {
    Http::fake();

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    MobileDevice::factory()->for($rh, 'usuario')->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->postJson('/api/v1/colaborador/solicitudes', [
            'tipo' => 'constancia_laboral',
            'motivo' => 'Trámite bancario.',
        ])
        ->assertCreated();

    expect($rh->fresh()->notifications()->count())->toBe(1);
    Http::assertSent(fn ($request) => $request->url() === config('expo.endpoint'));
});
