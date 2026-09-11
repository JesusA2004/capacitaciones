<?php

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Notifications\Mobile\SolicitudActualizadaNotification;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('mover una solicitud de enviada a en_revision notifica al colaborador', function () {
    Notification::fake();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->actingAs($rh)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'en_revision'])
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh()->estado->value)->toBe('en_revision');

    Notification::assertSentTo($solicitud->usuario, SolicitudActualizadaNotification::class);
});

test('mover una solicitud de en_revision a aprobada notifica al colaborador', function () {
    Notification::fake();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'en_revision']);

    $this->actingAs($rh)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'aprobada'])
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh())
        ->estado->value->toBe('aprobada')
        ->revisado_por->toBe($rh->id);

    Notification::assertSentTo($solicitud->usuario, SolicitudActualizadaNotification::class);
});

test('mover una solicitud a rechazada sin comentario falla', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'en_revision']);

    $this->actingAs($rh)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'rechazada'])
        ->assertSessionHasErrors('comentario');

    expect($solicitud->fresh()->estado->value)->toBe('en_revision');
});

test('mover una solicitud a rechazada con motivo la rechaza', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'en_revision']);

    $this->actingAs($rh)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), [
            'estado' => 'rechazada',
            'motivo_rechazo' => 'Documentación incompleta.',
        ])
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh())
        ->estado->value->toBe('rechazada')
        ->motivo_rechazo->toBe('Documentación incompleta.');
});

test('un usuario sin permiso de revision no puede mover una solicitud en el tablero', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->actingAs($colaborador)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'en_revision'])
        ->assertForbidden();

    expect($solicitud->fresh()->estado->value)->toBe('enviada');
});

test('un jefe_directo no puede mover una solicitud fuera de su equipo', function () {
    $jefe = User::factory()->create();
    $jefe->assignRole('jefe_directo');

    $ajeno = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $ajeno->id, 'estado' => 'enviada']);

    $this->actingAs($jefe)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'en_revision'])
        ->assertForbidden();
});

test('el tablero solo trae estados gestionables y excluye canceladas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $cancelada = SolicitudInterna::factory()->create(['estado' => 'cancelada']);
    $enviada = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Solicitudes/Index')
            ->where('solicitudes', fn ($lista) => collect($lista)->pluck('id')->contains($enviada->id)
                && ! collect($lista)->pluck('id')->contains($cancelada->id))
        );
});
