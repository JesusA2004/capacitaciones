<?php

use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('no se puede generar un alta digital de un candidato que no esta seleccionado', function () {
    $candidato = Candidato::factory()->create(['estado' => 'entrevista_pendiente']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.store'), ['candidato_id' => $candidato->id])
        ->assertSessionHasErrors('candidato_id');

    expect(AltaDigital::where('candidato_id', $candidato->id)->exists())->toBeFalse();
});

test('no se puede generar un qr de incorporacion de un candidato que no esta seleccionado', function () {
    $candidato = Candidato::factory()->create(['estado' => 'entrevista_pendiente']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.incorporacion.invitaciones.store'), ['candidato_id' => $candidato->id, 'duracion_horas' => 24])
        ->assertSessionHasErrors('candidato_id');

    expect(IncorporacionInvitacion::where('candidato_id', $candidato->id)->exists())->toBeFalse();
});

test('un candidato autorizado por rh si genera su alta digital y, ya en contratación, su qr queda ligado a él', function () {
    $candidato = Candidato::factory()->create(['estado' => 'autorizado_rh']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.store'), ['candidato_id' => $candidato->id])
        ->assertSessionHasNoErrors();

    expect(AltaDigital::where('candidato_id', $candidato->id)->exists())->toBeTrue();

    // El QR se reemite desde Invitaciones para el candidato ya en
    // contratación (su persona ya existe).
    $candidato->update(['estado' => 'en_contratacion', 'colaborador_id' => clColaboradorEnContratacion()->id]);

    $this->actingAs($usuario)
        ->post(route('rh.incorporacion.invitaciones.store'), ['candidato_id' => $candidato->id, 'duracion_horas' => 24])
        ->assertSessionHasNoErrors();

    $invitacion = IncorporacionInvitacion::where('candidato_id', $candidato->id)->first();

    expect($invitacion)->not->toBeNull()
        ->and($invitacion->candidato_id)->toBe($candidato->id);
});

test('una invitacion qr siempre va ligada a un candidato y dura como maximo 24 horas', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    // Regla vigente (StoreIncorporacionInvitacionRequest): ya no existen QR
    // "sueltos" fuera del embudo de reclutamiento ni de vigencia larga.
    $this->actingAs($usuario)
        ->post(route('rh.incorporacion.invitaciones.store'), ['nombre_prellenado' => 'Invitado directo'])
        ->assertSessionHasErrors(['candidato_id', 'duracion_horas']);

    expect(IncorporacionInvitacion::where('nombre_prellenado', 'Invitado directo')->exists())->toBeFalse();
});
