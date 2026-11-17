<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\IncorporacionInvitacion;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Etapa 1 · alcance (R2): Invitaciones QR y Campañas se acotan en el backend
| a las sucursales de quien opera. Un gerente al que se le otorgan los
| permisos del módulo NO ve, regenera, revoca ni emite fuera de su sucursal.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);

    $estructura = clEstructura();
    $this->a = $estructura['sucursal'];
    $this->a->update(['es_corporativo' => false]);
    $this->b = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'es_corporativo' => false]);
    $this->gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id]);
});

test('invitaciones QR: el gerente solo ve y opera las de su sucursal', function () {
    $this->gerente->givePermissionTo([
        'rh.incorporacion.invitaciones.ver', 'rh.incorporacion.invitaciones.crear',
        'rh.incorporacion.invitaciones.regenerar', 'rh.incorporacion.invitaciones.revocar',
        'rh.incorporacion.invitaciones.qr.descargar',
    ]);

    $deA = IncorporacionInvitacion::factory()->create(['sucursal_id' => $this->a->id]);
    $deB = IncorporacionInvitacion::factory()->create(['sucursal_id' => $this->b->id]);

    $this->actingAs($this->gerente)->get(route('rh.incorporacion.invitaciones.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('invitaciones.data', fn ($filas) => collect($filas)->pluck('id')->all() === [$deA->id]));

    $this->actingAs($this->gerente)->get(route('rh.incorporacion.invitaciones.show', $deA))->assertOk();
    $this->actingAs($this->gerente)->get(route('rh.incorporacion.invitaciones.show', $deB))->assertForbidden();
    $this->actingAs($this->gerente)->get(route('rh.incorporacion.invitaciones.qr', $deB))->assertForbidden();
    $this->actingAs($this->gerente)->post(route('rh.incorporacion.invitaciones.regenerar', $deB))->assertForbidden();
    $this->actingAs($this->gerente)->post(route('rh.incorporacion.invitaciones.revocar', $deB))->assertForbidden();

    expect($deB->fresh()->estado)->toBe(EstadoInvitacionIncorporacion::Activo);

    $candidatoB = Candidato::factory()->create([
        'estado' => EstadoCandidato::EnContratacion,
        'colaborador_id' => clColaboradorEnContratacion()->id,
        'sucursal_id' => $this->b->id,
    ]);

    $this->actingAs($this->gerente)
        ->post(route('rh.incorporacion.invitaciones.store'), ['candidato_id' => $candidatoB->id, 'duracion_horas' => 24])
        ->assertForbidden();

    expect(IncorporacionInvitacion::query()->where('candidato_id', $candidatoB->id)->exists())->toBeFalse();
});

test('campañas: el gerente no ve ni edita ni borra campañas de otra sucursal', function () {
    $this->gerente->givePermissionTo(['reclutamiento.campanas.ver', 'reclutamiento.campanas.editar', 'reclutamiento.campanas.eliminar']);

    $periodo = ['canal' => 'meta', 'mes' => (int) now()->format('n'), 'anio' => (int) now()->format('Y'), 'monto' => 1000];
    $deA = CampanaReclutamiento::query()->create([...$periodo, 'sucursal_id' => $this->a->id]);
    $deB = CampanaReclutamiento::query()->create([...$periodo, 'sucursal_id' => $this->b->id]);
    $general = CampanaReclutamiento::query()->create($periodo);

    $this->actingAs($this->gerente)->get(route('rh.campanas.index'))
        ->assertOk()
        ->assertInertia(function (Assert $p) use ($deA, $general): void {
            $ids = collect($p->toArray()['props']['campanas']['data'])->pluck('id')->sort()->values()->all();
            expect($ids)->toBe([$deA->id, $general->id]);
        });

    $this->actingAs($this->gerente)->delete(route('rh.campanas.destroy', $deB))->assertForbidden();
    $this->actingAs($this->gerente)->put(route('rh.campanas.update', $deB), ['canal' => 'meta', 'monto' => 1])->assertForbidden();

    expect(CampanaReclutamiento::query()->whereKey($deB->id)->exists())->toBeTrue();
});
