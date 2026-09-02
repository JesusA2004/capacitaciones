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

    expect($candidato->estado->value)->toBe('nuevo')
        ->and($candidato->seguimientos()->count())->toBe(1);
});

test('gerente_sucursal puede aprobar un candidato pero no rechazar fuera de su alcance', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null]);
    $usuario = User::factory()->create();
    $usuario->assignRole('gerente_sucursal');

    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'aprobado_gerencia'])
        ->assertSessionHasNoErrors();

    expect($candidato->fresh()->estado->value)->toBe('aprobado_gerencia');
});

test('rh_auxiliar no puede aprobar ni rechazar candidatos', function () {
    $candidato = Candidato::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'aprobado_rh'])
        ->assertForbidden();

    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'rechazado'])
        ->assertForbidden();
});

test('rh_auxiliar sí puede mover estados rutinarios de un candidato', function () {
    $candidato = Candidato::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'contactado'])
        ->assertSessionHasNoErrors();

    expect($candidato->fresh()->estado->value)->toBe('contactado');
});

test('un colaborador no puede ver candidatos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('rh.candidatos.index'))
        ->assertForbidden();
});
