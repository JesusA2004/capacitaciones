<?php

use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un gerente_sucursal solo ve colaboradores de su propia sucursal en el listado', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $gerente->assignRole('gerente_sucursal');

    User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $this->actingAs($gerente)
        ->get(route('administracion.usuarios.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('usuarios.data', 2)); // el gerente + su colaborador de la sucursal A

    $idsVisibles = app(AlcanceOrganizacionalService::class)
        ->limitarUsuariosPorAlcance(User::query(), $gerente)
        ->pluck('sucursal_principal_id')
        ->unique();

    expect($idsVisibles)->toHaveCount(1)->and($idsVisibles->first())->toBe($sucursalA->id);
});

test('un super_admin ve colaboradores de todas las sucursales', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();

    $admin = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $admin->assignRole('super_admin');

    User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $this->actingAs($admin)
        ->get(route('administracion.usuarios.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('usuarios.data', 3));
});

test('un gerente_sucursal no puede editar a un colaborador de otra sucursal aunque adivine su id', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $this->actingAs($gerente)
        ->put(route('administracion.usuarios.update', $colaboradorAjeno), [
            'name' => 'Nombre Modificado',
            'sucursal_principal_id' => $sucursalB->id,
        ])
        ->assertForbidden();

    expect($colaboradorAjeno->fresh()->name)->not->toBe('Nombre Modificado');
});

test('un colaborador solo puede ver su propia informacion, no la de otros colaboradores', function () {
    $sucursal = Sucursal::factory()->create();

    $colaborador = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $colaborador->assignRole('colaborador');

    $otroColaborador = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);

    expect($colaborador->can('view', $colaborador))->toBeFalse(); // "usuarios.ver" no esta en el rol colaborador
    expect($colaborador->can('view', $otroColaborador))->toBeFalse();
});
