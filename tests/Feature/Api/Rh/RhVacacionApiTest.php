<?php

use App\Models\MobileDevice;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh puede listar vacaciones pendientes', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    SolicitudVacaciones::factory()->count(2)->create(['estado' => 'pendiente']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/vacaciones')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('rh puede ver el detalle de una vacacion', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $vacacion = SolicitudVacaciones::factory()->create(['estado' => 'pendiente']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson("/api/v1/rh/vacaciones/{$vacacion->id}")
        ->assertOk()
        ->assertJsonPath('data.acciones_permitidas', ['ver', 'aprobar', 'rechazar']);
});

test('rh puede aprobar vacaciones y se notifica al colaborador', function () {
    Http::fake();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    MobileDevice::factory()->for($colaborador, 'usuario')->create();
    $vacacion = SolicitudVacaciones::factory()->create(['user_id' => $colaborador->id, 'estado' => 'pendiente']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/vacaciones/{$vacacion->id}/aprobar")
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobada');

    expect($colaborador->fresh()->notifications()->count())->toBe(1);
    Http::assertSent(fn ($request) => $request->url() === config('expo.endpoint'));
});

test('rechazar vacaciones sin motivo falla', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $vacacion = SolicitudVacaciones::factory()->create(['estado' => 'pendiente']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/vacaciones/{$vacacion->id}/rechazar", [])
        ->assertUnprocessable();
});

test('un colaborador no tiene permiso para aprobar vacaciones desde rh', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $vacacion = SolicitudVacaciones::factory()->create(['estado' => 'pendiente']);

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/vacaciones/{$vacacion->id}/aprobar")
        ->assertForbidden();
});

test('solicitar vacaciones notifica y encola push para el aprobador', function () {
    Http::fake();

    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(3)]);
    $colaborador->assignRole('colaborador');
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    MobileDevice::factory()->for($rh, 'usuario')->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->postJson('/api/v1/vacaciones/solicitudes', [
            'fecha_inicio' => now()->addWeek()->toDateString(),
            'fecha_fin' => now()->addWeek()->addDays(4)->toDateString(),
            'dias_solicitados' => 5,
        ])
        ->assertCreated();

    expect($rh->fresh()->notifications()->count())->toBe(1);
    Http::assertSent(fn ($request) => $request->url() === config('expo.endpoint'));
});
