<?php

use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh puede listar vacantes paginadas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    Vacante::factory()->count(3)->create(['estado' => 'abierta']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/vacantes?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'puesto', 'sucursal', 'estado', 'plazas_requeridas', 'plazas_cubiertas', 'plazas_disponibles']],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
});

test('rh puede filtrar vacantes por estado', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    Vacante::factory()->create(['estado' => 'abierta']);
    Vacante::factory()->create(['estado' => 'cubierta']);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/vacantes?estado=cubierta')
        ->assertOk();

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.estado'))->toBe('cubierta');
});

test('un colaborador sin permiso no puede listar vacantes desde la app', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/vacantes')
        ->assertForbidden();
});
