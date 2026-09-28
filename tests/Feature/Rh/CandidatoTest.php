<?php

use App\Models\Candidato;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh_admin puede registrar un candidato y queda un seguimiento inicial', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), [
            'nombre' => 'Ana',
            'apellidos' => 'García',
            'correo' => 'ana.garcia@example.com',
        ])
        ->assertSessionHasNoErrors();

    $candidato = Candidato::where('correo', 'ana.garcia@example.com')->firstOrFail();

    // store() siembra el pipeline en EstadoCandidato::Recibidos — 'nuevo' no
    // es un valor del enum actual (pipeline de 10 fases + 4 estados de
    // salida, ver App\Enums\EstadoCandidato).
    expect($candidato->estado->value)->toBe('recibidos')
        ->and($candidato->seguimientos()->count())->toBe(1);
});

test('gerente_sucursal puede aprobar un candidato pero no rechazar fuera de su alcance', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null]);
    $usuario = User::factory()->create();
    $usuario->assignRole('gerente_sucursal');

    // 'aprobado_gerencia' no existe en el enum actual: mover a un estado de
    // la lista ESTADOS_APROBACION (CandidatoPolicy::cambiarEstado) exige el
    // permiso candidatos.aprobar, que gerente_sucursal sí tiene.
    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'oferta_aprobacion'])
        ->assertSessionHasNoErrors();

    expect($candidato->fresh()->estado->value)->toBe('oferta_aprobacion');
});

test('rh_auxiliar no puede aprobar ni rechazar candidatos', function () {
    $candidato = Candidato::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'listo_para_contratacion'])
        ->assertForbidden();

    // 'no_seleccionado' es uno de los 4 estados de salida reales
    // (ESTADOS_RECHAZO) — exige candidatos.rechazar, que rh_auxiliar no tiene.
    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'no_seleccionado'])
        ->assertForbidden();
});

test('rh_auxiliar sí puede mover estados rutinarios de un candidato', function () {
    $candidato = Candidato::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    // 'preseleccion' es un estado regular del pipeline (candidatos.editar),
    // no una decisión de aprobación/rechazo.
    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'preseleccion'])
        ->assertSessionHasNoErrors();

    expect($candidato->fresh()->estado->value)->toBe('preseleccion');
});

test('un colaborador no puede ver candidatos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('rh.candidatos.index'))
        ->assertForbidden();
});
