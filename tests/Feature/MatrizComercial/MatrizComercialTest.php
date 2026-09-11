<?php

use App\Models\NodoComercial;
use App\Models\User;
use Database\Seeders\MatrizComercialSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(SucursalSeeder::class);
    $this->seed(MatrizComercialSeeder::class);
});

test('rh_admin puede ver la matriz comercial', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->get(route('administracion.matriz-comercial.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Administracion/MatrizComercial/Index')
            ->has('arbol')
            ->has('resumen.total_rutas')
        );
});

test('un gerente con solo organigrama.ver puede consultar la matriz', function () {
    $usuario = User::factory()->create();
    $usuario->givePermissionTo('organigrama.ver');

    $this->actingAs($usuario)
        ->get(route('administracion.matriz-comercial.index'))
        ->assertOk();
});

test('un colaborador sin permisos no puede ver la matriz comercial', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->get(route('administracion.matriz-comercial.index'))
        ->assertForbidden();
});

test('el seeder no duplica nodos al correr dos veces', function () {
    $antes = NodoComercial::count();

    $this->seed(MatrizComercialSeeder::class);

    expect(NodoComercial::count())->toBe($antes);
});

test('nodos con INACTIVA quedan marcados inactivos y sin el sufijo en el nombre', function () {
    $ruta = NodoComercial::where('nombre', 'ZACATEPEC')->first();

    expect($ruta)->not->toBeNull()
        ->and($ruta->activa)->toBeFalse();
});

test('dos rutas con el mismo nombre base pero distinto estado no colisionan', function () {
    $vencidos = NodoComercial::where('nombre', 'HUAMANTLA (VENCIDOS)')->first();
    $castigo = NodoComercial::where('nombre', 'HUAMANTLA (CASTIGO)')->first();

    expect($vencidos)->not->toBeNull()
        ->and($castigo)->not->toBeNull()
        ->and($vencidos->id)->not->toBe($castigo->id)
        ->and($vencidos->metadata['estado_operativo'])->toBe('vencidos')
        ->and($castigo->metadata['estado_operativo'])->toBe('castigo');
});

test('rh_admin puede asignar un gestor a una ruta activa', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $gestor = User::factory()->create(['estatus' => 'activo']);
    $ruta = NodoComercial::where('tipo', 'ruta')->where('activa', true)->first();

    $this->actingAs($rh)
        ->put(route('administracion.matriz-comercial.responsable', $ruta), [
            'responsable_user_id' => $gestor->id,
        ])
        ->assertSessionHasNoErrors();

    expect($ruta->fresh()->responsable_user_id)->toBe($gestor->id);
});
