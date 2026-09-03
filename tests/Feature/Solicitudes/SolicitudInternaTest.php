<?php

use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador puede crear una solicitud interna y queda enviada', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'constancia_laboral',
            'motivo' => 'La necesito para trámite bancario.',
        ])
        ->assertSessionHasNoErrors();

    $solicitud = SolicitudInterna::where('user_id', $colaborador->id)->first();

    expect($solicitud)->not->toBeNull()
        ->and($solicitud->estado->value)->toBe('enviada')
        ->and($solicitud->folio)->toStartWith('SOL-')
        ->and($solicitud->historial()->count())->toBe(2);
});

test('un colaborador solo ve sus propias solicitudes en su listado', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $otro = User::factory()->create();

    SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);
    SolicitudInterna::factory()->create(['user_id' => $otro->id]);

    $respuesta = $this->actingAs($colaborador)->get(route('solicitudes.index'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        expect($page->toArray()['props']['solicitudes']['total'])->toBe(1);
    });
});

test('un colaborador no puede ver la solicitud de otro colaborador', function () {
    $colaborador = User::factory()->create();
    $otro = User::factory()->create();
    $solicitudAjena = SolicitudInterna::factory()->create(['user_id' => $otro->id]);

    $this->actingAs($colaborador)
        ->get(route('solicitudes.show', $solicitudAjena))
        ->assertForbidden();
});

test('un colaborador puede cancelar su propia solicitud mientras no sea definitiva', function () {
    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);

    $this->actingAs($colaborador)
        ->post(route('solicitudes.cancelar', $solicitud))
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh()->estado->value)->toBe('cancelada');
});

test('rh_admin puede aprobar cualquier solicitud interna', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh()->estado->value)->toBe('aprobada')
        ->and($solicitud->fresh()->revisado_por)->toBe($rh->id);
});

test('rh_admin puede rechazar una solicitud con motivo', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create();

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'Documentación incompleta.'])
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh())
        ->estado->value->toBe('rechazada')
        ->motivo_rechazo->toBe('Documentación incompleta.');
});

test('un jefe_directo solo puede revisar solicitudes de sus subordinados directos', function () {
    $jefe = User::factory()->create();
    $jefe->assignRole('jefe_directo');

    $subordinado = User::factory()->create(['jefe_id' => $jefe->id]);
    $otro = User::factory()->create();

    $solicitudPropia = SolicitudInterna::factory()->create(['user_id' => $subordinado->id]);
    $solicitudAjena = SolicitudInterna::factory()->create(['user_id' => $otro->id]);

    $this->actingAs($jefe)
        ->post(route('rh.solicitudes.aprobar', $solicitudPropia))
        ->assertSessionHasNoErrors();

    $this->actingAs($jefe)
        ->post(route('rh.solicitudes.aprobar', $solicitudAjena))
        ->assertForbidden();
});

test('un colaborador no puede revisar ni aprobar su propia solicitud aunque tenga el rol', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitudPropia = SolicitudInterna::factory()->create(['user_id' => $rh->id]);

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.aprobar', $solicitudPropia))
        ->assertForbidden();
});

test('un auditor solo puede leer solicitudes, no aprobarlas', function () {
    $auditor = User::factory()->create();
    $auditor->assignRole('auditor');

    $solicitud = SolicitudInterna::factory()->create();

    $this->actingAs($auditor)
        ->get(route('rh.solicitudes.show', $solicitud))
        ->assertOk();

    $this->actingAs($auditor)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertForbidden();
});
