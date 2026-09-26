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
    $candidato = Candidato::factory()->create(['estado' => 'entrevista']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.store'), ['candidato_id' => $candidato->id])
        ->assertSessionHasErrors('candidato_id');

    expect(AltaDigital::where('candidato_id', $candidato->id)->exists())->toBeFalse();
});

test('no se puede generar un qr de incorporacion de un candidato que no esta seleccionado', function () {
    $candidato = Candidato::factory()->create(['estado' => 'entrevista']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.incorporacion.invitaciones.store'), ['candidato_id' => $candidato->id, 'duracion_horas' => 24])
        ->assertSessionHasErrors('candidato_id');

    expect(IncorporacionInvitacion::where('candidato_id', $candidato->id)->exists())->toBeFalse();
});

test('un candidato seleccionado si genera su alta digital y su qr queda ligado a el', function () {
    $candidato = Candidato::factory()->create(['estado' => 'listo_para_contratacion']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.store'), ['candidato_id' => $candidato->id])
        ->assertSessionHasNoErrors();

    expect(AltaDigital::where('candidato_id', $candidato->id)->exists())->toBeTrue();

    // El QR de incorporacion tambien puede generarse para el mismo
    // candidato ya seleccionado (paso final del flujo, ver
    // Rh/Altas/Show.vue "Generar QR de incorporación").
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
