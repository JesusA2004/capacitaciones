<?php

use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

// Las vacaciones ya NO viven en `solicitudes_vacaciones`: son un `tipo` de
// solicitud interna (motor unificado). La bandeja las cuenta como solicitudes
// abiertas, no desde la tabla legacy que ya no recibe altas.
test('la bandeja de pendientes pagina y trae acciones_permitidas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    SolicitudInterna::factory()->count(3)->create(['estado' => 'enviada']);
    SolicitudInterna::factory()->count(2)->create(['estado' => 'enviada', 'tipo' => 'vacaciones']);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/pendientes?per_page=2')
        ->assertOk();

    expect($respuesta->json('meta.total'))->toBe(5)
        ->and($respuesta->json('meta.vacaciones'))->toBe(2)
        ->and($respuesta->json('meta.solicitudes'))->toBe(3)
        ->and(count($respuesta->json('data')))->toBe(2)
        ->and($respuesta->json('data.0.acciones_permitidas'))->toContain('ver');
});

test('el filtro tipo=solicitud excluye las vacaciones', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    SolicitudInterna::factory()->count(2)->create(['estado' => 'enviada']);
    SolicitudInterna::factory()->count(2)->create(['estado' => 'enviada', 'tipo' => 'vacaciones']);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/pendientes?tipo=solicitud')
        ->assertOk();

    expect($respuesta->json('meta.total'))->toBe(2);
    foreach ($respuesta->json('data') as $item) {
        expect($item['tipo'])->toBe('solicitud');
    }
});

test('la baja de colaborador no aparece en la bandeja de pendientes', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    SolicitudInterna::factory()->create(['estado' => 'enviada']);
    SolicitudInterna::factory()->create(['estado' => 'enviada', 'tipo' => 'baja_colaborador']);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/pendientes')
        ->assertOk();

    expect($respuesta->json('meta.total'))->toBe(1);
    foreach ($respuesta->json('data') as $item) {
        expect($item['titulo'])->not->toBe('Baja de colaborador');
    }
});

test('un gerente de sucursal solo ve pendientes de su propia sucursal', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();

    $gerente = User::factory()->for(Colaborador::factory()->state(['sucursal_principal_id' => $sucursalA->id]), 'colaborador')->create();
    $gerente->assignRole('gerente_sucursal');

    $colaboradorA = User::factory()->for(Colaborador::factory()->state(['sucursal_principal_id' => $sucursalA->id]), 'colaborador')->create();
    $colaboradorB = User::factory()->for(Colaborador::factory()->state(['sucursal_principal_id' => $sucursalB->id]), 'colaborador')->create();

    SolicitudInterna::factory()->create(['user_id' => $colaboradorA->id, 'estado' => 'enviada', 'sucursal_id' => $sucursalA->id]);
    SolicitudInterna::factory()->create(['user_id' => $colaboradorB->id, 'estado' => 'enviada', 'sucursal_id' => $sucursalB->id]);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$gerente->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/pendientes?tipo=solicitud')
        ->assertOk();

    expect($respuesta->json('meta.total'))->toBe(1);
});

test('un colaborador sin permiso de rh no puede ver la bandeja de pendientes', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/pendientes')
        ->assertForbidden();
});
