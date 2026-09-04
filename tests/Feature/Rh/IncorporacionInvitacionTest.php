<?php

use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh_admin con permiso puede crear una invitacion de incorporacion por qr', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.incorporacion.invitaciones.store'), [
            'nombre_prellenado' => 'Luis Ramírez',
            'email' => 'luis@mrlana.test',
            'duracion_horas' => 72,
        ])
        ->assertRedirect();

    $invitacion = IncorporacionInvitacion::query()->where('email', 'luis@mrlana.test')->first();
    expect($invitacion)->not->toBeNull();
    expect($invitacion->estado)->toBe(EstadoInvitacionIncorporacion::Activo);
    expect($invitacion->creado_por_id)->toBe($rh->id);
});

test('un usuario sin permiso no puede crear una invitacion de incorporacion', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('rh.incorporacion.invitaciones.store'), ['nombre_prellenado' => 'Alguien'])
        ->assertForbidden();

    expect(IncorporacionInvitacion::query()->count())->toBe(0);
});

test('rh_auxiliar puede crear pero no revocar ni regenerar invitaciones', function () {
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    $this->actingAs($auxiliar)
        ->post(route('rh.incorporacion.invitaciones.store'), ['nombre_prellenado' => 'Nueva'])
        ->assertRedirect();

    $invitacion = IncorporacionInvitacion::query()->firstOrFail();

    $this->actingAs($auxiliar)
        ->post(route('rh.incorporacion.invitaciones.revocar', $invitacion))
        ->assertForbidden();

    $this->actingAs($auxiliar)
        ->post(route('rh.incorporacion.invitaciones.regenerar', $invitacion))
        ->assertForbidden();
});

test('el token plano solo esta disponible en sesion los minutos siguientes a crear la invitacion', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)->post(route('rh.incorporacion.invitaciones.store'), ['nombre_prellenado' => 'Ana']);
    $invitacion = IncorporacionInvitacion::query()->firstOrFail();

    $vistaInmediata = $this->actingAs($rh)->get(route('rh.incorporacion.invitaciones.show', $invitacion));
    $vistaInmediata->assertInertia(fn ($page) => $page->where('tokenPlano', fn ($valor) => $valor !== null));

    // Sigue disponible para una segunda accion (p. ej. descargar el QR)
    // dentro de la misma ventana de vigencia.
    $vistaSiguiente = $this->actingAs($rh)->get(route('rh.incorporacion.invitaciones.show', $invitacion));
    $vistaSiguiente->assertInertia(fn ($page) => $page->where('tokenPlano', fn ($valor) => $valor !== null));

    // Pasada la ventana de vigencia, ya no se puede volver a ver.
    $this->travel(10)->minutes();
    $vistaTardia = $this->actingAs($rh)->get(route('rh.incorporacion.invitaciones.show', $invitacion));
    $vistaTardia->assertInertia(fn ($page) => $page->where('tokenPlano', null));
});

test('revocar deja la invitacion sin uso posible', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $invitacion = IncorporacionInvitacion::factory()->create(['creado_por_id' => $rh->id]);

    $this->actingAs($rh)
        ->post(route('rh.incorporacion.invitaciones.revocar', $invitacion))
        ->assertRedirect();

    expect($invitacion->fresh()->estado)->toBe(EstadoInvitacionIncorporacion::Revocado);
});

test('regenerar revoca la anterior y crea una invitacion nueva enlazada', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $invitacion = IncorporacionInvitacion::factory()->create(['creado_por_id' => $rh->id]);

    $this->actingAs($rh)
        ->post(route('rh.incorporacion.invitaciones.regenerar', $invitacion))
        ->assertRedirect();

    expect($invitacion->fresh()->estado)->toBe(EstadoInvitacionIncorporacion::Revocado);

    $nueva = IncorporacionInvitacion::query()->where('regenerated_from_id', $invitacion->id)->first();
    expect($nueva)->not->toBeNull();
    expect($nueva->estado)->toBe(EstadoInvitacionIncorporacion::Activo);
});
