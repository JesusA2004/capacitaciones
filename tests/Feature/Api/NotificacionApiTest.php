<?php

use App\Models\User;
use App\Notifications\Mobile\SolicitudActualizadaNotification;
use App\Models\SolicitudInterna;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function notificacionHeaders(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('leer todas solo marca las notificaciones propias', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create();

    $solicitud = SolicitudInterna::factory()->create(['user_id' => $usuario->id]);
    $usuario->notify(new SolicitudActualizadaNotification($solicitud));
    $usuario->notify(new SolicitudActualizadaNotification($solicitud));
    $otro->notify(new SolicitudActualizadaNotification($solicitud));

    $respuesta = $this->withHeaders(notificacionHeaders($usuario))
        ->postJson('/api/v1/notificaciones/leer-todas')
        ->assertOk()
        ->assertJsonStructure(['message', 'updated']);

    expect($respuesta->json('updated'))->toBe(2)
        ->and($usuario->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($otro->fresh()->unreadNotifications()->count())->toBe(1);
});

test('el payload de una notificacion trae data.type y data.resource_id', function () {
    $usuario = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $usuario->id]);
    $usuario->notify(new SolicitudActualizadaNotification($solicitud));

    $respuesta = $this->withHeaders(notificacionHeaders($usuario))
        ->getJson('/api/v1/notificaciones')
        ->assertOk();

    expect($respuesta->json('data.0.data.type'))->toBe('solicitud')
        ->and($respuesta->json('data.0.data.resource_id'))->toBe($solicitud->id);
});
