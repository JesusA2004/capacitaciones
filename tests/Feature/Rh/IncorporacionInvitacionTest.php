<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\Candidato;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

/** Alta Digital QR simplificado: el formulario ya no captura nombre/correo a mano, se autocompleta desde el Candidato elegido. */
function candidatoListoParaContratar(array $atributos = []): Candidato
{
    // Solo un candidato ya en contratación (autorizado por RH y con su
    // persona creada) recibe el QR desde este módulo.
    return Candidato::factory()->create([
        'estado' => EstadoCandidato::EnContratacion,
        'colaborador_id' => clColaboradorEnContratacion()->id,
        ...$atributos,
    ]);
}

test('rh_admin con permiso puede crear una invitacion de incorporacion por qr', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $candidato = candidatoListoParaContratar(['correo' => 'luis@mrlana.test']);

    $this->actingAs($rh)
        ->post(route('rh.incorporacion.invitaciones.store'), [
            'candidato_id' => $candidato->id,
            'duracion_horas' => 24,
        ])
        ->assertRedirect();

    $invitacion = IncorporacionInvitacion::query()->where('email', 'luis@mrlana.test')->first();
    expect($invitacion)->not->toBeNull();
    expect($invitacion->estado)->toBe(EstadoInvitacionIncorporacion::Activo);
    expect($invitacion->creado_por_id)->toBe($rh->id);
    expect($invitacion->candidato_id)->toBe($candidato->id);
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
    $candidato = candidatoListoParaContratar();

    $this->actingAs($auxiliar)
        ->post(route('rh.incorporacion.invitaciones.store'), ['candidato_id' => $candidato->id, 'duracion_horas' => 24])
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
    $candidato = candidatoListoParaContratar();

    $this->actingAs($rh)->post(route('rh.incorporacion.invitaciones.store'), ['candidato_id' => $candidato->id, 'duracion_horas' => 24]);
    $invitacion = IncorporacionInvitacion::query()->firstOrFail();

    $vistaInmediata = $this->actingAs($rh)->get(route('rh.incorporacion.invitaciones.show', $invitacion));
    $vistaInmediata->assertInertia(fn ($page) => $page->where('tokenPlano', fn ($valor) => $valor !== null));

    // Sigue disponible para una segunda accion (p. ej. descargar el QR)
    // dentro de la misma ventana de vigencia.
    $vistaSiguiente = $this->actingAs($rh)->get(route('rh.incorporacion.invitaciones.show', $invitacion));
    $vistaSiguiente->assertInertia(fn ($page) => $page->where('tokenPlano', fn ($valor) => $valor !== null));

    // Pasada la ventana de vigencia del token en sesión (5 min), "Ver" ya no
    // puede volver a mostrar el token plano de ESTA invitación — pero como
    // la invitación lógica sigue activa y no vencida, el controlador la
    // regenera de forma transparente (misma persona, nueva invitación
    // enlazada) en vez de dejar a RH sin ninguna forma de compartir el QR.
    $this->travel(10)->minutes();
    $vistaTardia = $this->actingAs($rh)->get(route('rh.incorporacion.invitaciones.show', $invitacion));
    $vistaTardia->assertRedirect();

    expect($invitacion->fresh()->estado)->toBe(EstadoInvitacionIncorporacion::Revocado);
    $regenerada = IncorporacionInvitacion::query()->where('regenerated_from_id', $invitacion->id)->firstOrFail();

    $vistaRegenerada = $this->actingAs($rh)->get(route('rh.incorporacion.invitaciones.show', $regenerada));
    $vistaRegenerada->assertInertia(fn ($page) => $page->where('tokenPlano', fn ($valor) => $valor !== null));
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

test('un qr ya usado no se puede regenerar: se oculta el botón y el backend lo rechaza', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $invitacion = IncorporacionInvitacion::factory()->create([
        'creado_por_id' => $rh->id,
        'estado' => EstadoInvitacionIncorporacion::Usado->value,
        'used_at' => now(),
        'usos_count' => 1,
    ]);

    $this->actingAs($rh)
        ->get(route('rh.incorporacion.invitaciones.show', $invitacion))
        ->assertInertia(fn ($page) => $page->where('puedeRegenerar', false)->where('yaUsada', true));

    $this->actingAs($rh)
        ->post(route('rh.incorporacion.invitaciones.regenerar', $invitacion))
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'error');

    expect(IncorporacionInvitacion::query()->where('regenerated_from_id', $invitacion->id)->exists())->toBeFalse();
});
