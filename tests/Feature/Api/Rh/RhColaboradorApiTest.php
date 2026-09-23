<?php

use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh puede listar colaboradores paginados', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    User::factory()->count(3)->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/colaboradores?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('rh puede ver el detalle basico de un colaborador', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = Colaborador::factory()->create([
        'contacto_emergencia_nombre' => 'María Elena Ruiz',
        'contacto_emergencia_telefono' => '5510000010',
    ]);
    User::factory()->for($colaborador, 'colaborador')->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson("/api/v1/rh/colaboradores/{$colaborador->id}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'nombre', 'contacto_emergencia' => ['nombre', 'telefono'], 'resumen' => ['solicitudes_pendientes', 'vacaciones_pendientes', 'documentos_pendientes']]])
        ->assertJsonPath('data.contacto_emergencia.nombre', 'María Elena Ruiz')
        ->assertJsonPath('data.contacto_emergencia.telefono', '5510000010');
});

test('un colaborador sin permiso no puede usar el directorio de rh', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/colaboradores')
        ->assertForbidden();
});
