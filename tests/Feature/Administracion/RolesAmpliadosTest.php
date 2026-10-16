<?php

use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('director_comercial tiene alcance global de solo lectura y no puede aprobar solicitudes', function () {
    $director = User::factory()->create();
    $director->assignRole('director_comercial');

    expect(app(AlcanceOrganizacionalService::class)->tieneAlcanceGlobal($director))->toBeTrue()
        ->and($director->can('reportes_rh.globales'))->toBeTrue()
        ->and($director->can('solicitudes.aprobar'))->toBeFalse()
        ->and($director->can('expedientes.editar'))->toBeFalse();
});

test('un gerente_regional ve colaboradores de varias sucursales asignadas', function () {
    $gerenteRegional = User::factory()->create();
    $gerenteRegional->assignRole('gerente_regional');

    $sucursalPrincipal = Sucursal::factory()->create();
    $sucursalAdicional = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();

    $gerenteRegional->colaborador->update(['sucursal_principal_id' => $sucursalPrincipal->id]);
    $gerenteRegional->colaborador->sucursalesAdicionales()->attach($sucursalAdicional->id);

    $colaboradorA = User::factory()->create(['sucursal_principal_id' => $sucursalPrincipal->id]);
    $colaboradorB = User::factory()->create(['sucursal_principal_id' => $sucursalAdicional->id]);
    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $alcance = app(AlcanceOrganizacionalService::class);

    expect($alcance->puedeVerUsuario($gerenteRegional, $colaboradorA))->toBeTrue()
        ->and($alcance->puedeVerUsuario($gerenteRegional, $colaboradorB))->toBeTrue()
        ->and($alcance->puedeVerUsuario($gerenteRegional, $colaboradorAjeno))->toBeFalse();
});

test('un subgerente usa PEOPLE como colaborador: ni revisa ni aprueba solicitudes de RH', function () {
    $sucursal = Sucursal::factory()->create();

    $subgerente = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $subgerente->assignRole('subgerente');

    $colaborador = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);

    expect($subgerente->can('solicitudes.revisar'))->toBeFalse()
        ->and($subgerente->can('solicitudes.aprobar'))->toBeFalse()
        ->and($subgerente->can('portal.solicitudes.crear'))->toBeTrue();

    $this->actingAs($subgerente)
        ->get(route('rh.solicitudes.show', $solicitud))
        ->assertForbidden();

    $this->actingAs($subgerente)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertForbidden();

    expect($solicitud->fresh()->estado->value)->not->toBe('aprobada');
});

test('una coordinadora usa PEOPLE como colaboradora: ni revisa ni aprueba solicitudes de RH', function () {
    $coordinadora = User::factory()->create();
    $coordinadora->assignRole('coordinadora');

    expect($coordinadora->can('solicitudes.revisar'))->toBeFalse()
        ->and($coordinadora->can('solicitudes.aprobar'))->toBeFalse()
        ->and($coordinadora->can('portal.solicitudes.crear'))->toBeTrue();
});
