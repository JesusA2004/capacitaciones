<?php

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Notifications\Mobile\SolicitudActualizadaNotification;
use App\Services\Solicitudes\SolicitudesService;
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
        ->get(route('rh.solicitudes.index', ['todas' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Solicitudes/Index')
            ->where('solicitudes', fn ($lista) => collect($lista)->pluck('id')->contains($enviada->id)
                && ! collect($lista)->pluck('id')->contains($cancelada->id))
            ->where('solicitudesResumen.total', 1)
            ->where('solicitudesResumen.mostradas', 1)
        );
});

test('si hay más solicitudes activas que el límite del tablero, el resumen expone el total real en vez de esconderlas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    SolicitudInterna::factory()->count(3)->create(['estado' => 'enviada']);

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.index', ['todas' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('solicitudesResumen.total', 3)
            ->where('solicitudesResumen.mostradas', 3)
            ->where('solicitudesResumen.limite', 500)
        );
});

test('no se puede mover una solicitud aprobada (definitiva) de vuelta a enviada', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'aprobada']);

    $this->actingAs($rh)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'enviada'])
        ->assertSessionHasErrors('estado');

    expect($solicitud->fresh()->estado->value)->toBe('aprobada');
});

test('no se puede mover una solicitud rechazada directo a aprobada', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'rechazada']);

    $this->actingAs($rh)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'aprobada'])
        ->assertSessionHasErrors('estado');

    expect($solicitud->fresh()->estado->value)->toBe('rechazada');
});

test('Permanencia › Solicitudes abre directo el tablero con todos los tipos y el filtro de tipo lo acota (propuesta etapa 1, pantalla 3)', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    SolicitudInterna::factory()->count(2)->create(['tipo' => 'prestamo', 'estado' => 'enviada']);
    SolicitudInterna::factory()->create(['tipo' => 'vacaciones', 'estado' => 'en_revision']);

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Solicitudes/Index')
            ->has('solicitudes', 3)
            ->where('tiposFiltro', fn ($tipos) => collect($tipos)->pluck('value')->contains('prestamo')));

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.index', ['tipo' => 'prestamo']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Solicitudes/Index')->where('filtros.tipo', 'prestamo')->has('solicitudes', 2));
});

test('Incapacidad sale de Solicitudes: no está en la lista de tipos ni en «Todos los tipos», y el histórico se conserva', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $permiso = SolicitudInterna::factory()->create(['tipo' => 'permiso', 'estado' => 'enviada']);
    $historica = SolicitudInterna::factory()->create(['tipo' => 'incapacidad', 'estado' => 'en_revision']);

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('solicitudes', fn ($lista) => collect($lista)->pluck('id')->all() === [$permiso->id])
            ->where('solicitudesResumen.total', 1)
            ->where('tiposFiltro', fn ($tipos) => ! collect($tipos)->pluck('value')->contains('incapacidad')));

    // La exportación respeta lo mismo que el tablero.
    $filas = app(SolicitudesService::class)->paraExportar($rh, []);
    expect($filas->pluck('id')->all())->toBe([$permiso->id]);

    // El registro histórico sigue en la base y se consulta pidiéndolo de forma explícita.
    $this->actingAs($rh)
        ->get(route('rh.solicitudes.index', ['tipo' => 'incapacidad']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('solicitudes', fn ($lista) => collect($lista)->pluck('id')->all() === [$historica->id]));

    $this->actingAs($rh)->get(route('rh.solicitudes.show', $historica))->assertOk();
});

test('el conteo de pendientes de Solicitudes no incluye incapacidades ni bajas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    SolicitudInterna::factory()->create(['tipo' => 'permiso', 'estado' => 'enviada']);
    SolicitudInterna::factory()->create(['tipo' => 'incapacidad', 'estado' => 'enviada']);

    $resumen = app(SolicitudesService::class)->resumenPorTipo($rh);

    expect(collect($resumen)->pluck('clave')->all())->not->toContain('incapacidad', 'baja_colaborador')
        ->and(array_sum(array_column($resumen, 'abiertas')))->toBe(1);
});
