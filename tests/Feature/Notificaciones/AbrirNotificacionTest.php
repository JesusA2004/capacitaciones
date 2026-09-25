<?php

use App\Enums\EstadoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Notifications\Mobile\RhSolicitudCreadaNotification;
use App\Notifications\Mobile\SolicitudActualizadaNotification;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('abrir una notificacion pendiente de RH lleva a la solicitud, la marca leida y descuenta el contador', function () {
    $rh = User::factory()->create();
    $solicitante = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $solicitante->id]);

    $rh->notify(new RhSolicitudCreadaNotification($solicitud));
    $rh->notify(new RhSolicitudCreadaNotification($solicitud));
    $notificacion = $rh->notifications()->firstOrFail();

    $respuesta = $this->actingAs($rh)
        ->postJson("/notificaciones/{$notificacion->id}/abrir")
        ->assertOk();

    expect($respuesta->json('url'))->toBe("/rh/solicitudes/{$solicitud->id}")
        ->and($respuesta->json('atendida'))->toBeFalse()
        ->and($respuesta->json('mensaje_estado'))->toContain('sigue pendiente')
        ->and($respuesta->json('no_leidas'))->toBe(1)
        ->and($notificacion->fresh()->read_at)->not->toBeNull();
});

test('si la solicitud ya se resolvio en otro lado, abrir el aviso en la app dice que ya fue atendida', function () {
    $rh = User::factory()->create(['name' => 'Ana', 'apellidos' => 'López']);
    $solicitante = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $solicitante->id]);
    $rh->notify(new RhSolicitudCreadaNotification($solicitud));

    // Se resuelve (p. ej. desde la web) después de enviado el aviso.
    $solicitud->update([
        'estado' => EstadoSolicitudInterna::Aprobada->value,
        'revisado_por' => $rh->id,
    ]);

    $notificacion = $rh->notifications()->firstOrFail();
    $token = $rh->createToken('test')->plainTextToken;

    $respuesta = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/abrir")
        ->assertOk();

    expect($respuesta->json('data.atendida'))->toBeTrue()
        ->and($respuesta->json('data.estado_recurso'))->toBe(EstadoSolicitudInterna::Aprobada->etiqueta())
        ->and($respuesta->json('data.mensaje_estado'))->toContain('ya fue atendida por Ana López')
        ->and($respuesta->json('data.no_leidas'))->toBe(0);
});

test('el colaborador que abre el aviso de su propia solicitud va a su detalle y ve el estado actual', function () {
    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'estado' => EstadoSolicitudInterna::Rechazada->value,
    ]);
    $colaborador->notify(new SolicitudActualizadaNotification($solicitud));
    $notificacion = $colaborador->notifications()->firstOrFail();

    $respuesta = $this->actingAs($colaborador)
        ->postJson("/notificaciones/{$notificacion->id}/abrir")
        ->assertOk();

    expect($respuesta->json('url'))->toBe("/solicitudes/{$solicitud->id}")
        ->and($respuesta->json('atendida'))->toBeNull()
        ->and($respuesta->json('mensaje_estado'))->toContain('Rechazada');
});

test('abrir un aviso cuyo recurso ya no existe no truena y lo marca leido', function () {
    $rh = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create();
    $rh->notify(new RhSolicitudCreadaNotification($solicitud));
    $solicitud->forceDelete();
    $notificacion = $rh->notifications()->firstOrFail();

    $respuesta = $this->actingAs($rh)
        ->postJson("/notificaciones/{$notificacion->id}/abrir")
        ->assertOk();

    expect($respuesta->json('atendida'))->toBeNull()
        ->and($respuesta->json('mensaje_estado'))->toBe('Esta solicitud ya no existe.')
        ->and($notificacion->fresh()->read_at)->not->toBeNull();
});

test('no se puede abrir la notificacion de otro usuario', function () {
    $duenio = User::factory()->create();
    $intruso = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $duenio->id]);
    $duenio->notify(new SolicitudActualizadaNotification($solicitud));
    $notificacion = $duenio->notifications()->firstOrFail();

    $this->actingAs($intruso)
        ->postJson("/notificaciones/{$notificacion->id}/abrir")
        ->assertNotFound();

    expect($notificacion->fresh()->read_at)->toBeNull();
});
