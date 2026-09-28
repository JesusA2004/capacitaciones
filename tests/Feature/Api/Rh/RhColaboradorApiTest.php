<?php

use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
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

test('vacaciones_pendientes cuenta la solicitud unificada real, no solo la tabla legacy', function () {
    // Bug corregido: el resumen leía solo `solicitudes_vacaciones` (legacy),
    // que ya no recibe nada del flujo unificado actual — mostraba 0
    // pendientes aunque el colaborador sí tuviera una solicitud real de
    // vacaciones esperando revisión.
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = Colaborador::factory()->create();
    User::factory()->for($colaborador, 'colaborador')->create();

    SolicitudInterna::factory()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => TipoSolicitudInterna::Vacaciones,
        'estado' => 'enviada',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson("/api/v1/rh/colaboradores/{$colaborador->id}")
        ->assertOk()
        ->assertJsonPath('data.resumen.vacaciones_pendientes', 1);
});

test('un colaborador sin permiso no puede usar el directorio de rh', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/colaboradores')
        ->assertForbidden();
});
