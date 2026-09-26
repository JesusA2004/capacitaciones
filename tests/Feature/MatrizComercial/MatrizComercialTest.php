<?php

use App\Models\AsignacionNodoComercial;
use App\Models\NodoComercial;
use App\Models\User;
use App\Services\MatrizComercial\MatrizComercialService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
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
            'responsable_colaborador_id' => $gestor->colaborador_id,
        ])
        ->assertSessionHasNoErrors();

    expect($ruta->fresh()->responsable_colaborador_id)->toBe($gestor->colaborador_id);
});

test('asignar un gestor nuevo cierra la asignacion activa anterior y crea historial', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $gestorAnterior = User::factory()->create(['estatus' => 'activo']);
    $gestorNuevo = User::factory()->create(['estatus' => 'activo']);
    $ruta = NodoComercial::where('tipo', 'ruta')->where('activa', true)->first();

    app(MatrizComercialService::class)->asignarResponsable($ruta, $gestorAnterior->colaborador);

    $this->actingAs($rh)
        ->put(route('administracion.matriz-comercial.responsable', $ruta), [
            'responsable_colaborador_id' => $gestorNuevo->colaborador_id,
        ])
        ->assertSessionHasNoErrors();

    expect($ruta->fresh()->responsable_colaborador_id)->toBe($gestorNuevo->colaborador_id)
        ->and(AsignacionNodoComercial::where('nodo_comercial_id', $ruta->id)->count())->toBe(2)
        ->and(AsignacionNodoComercial::where('colaborador_id', $gestorAnterior->colaborador_id)->first()->activo)->toBeFalse()
        ->and(AsignacionNodoComercial::where('colaborador_id', $gestorNuevo->colaborador_id)->first()->activo)->toBeTrue();
});

test('rh_admin puede agregar y quitar un apoyo de una ruta', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $apoyo = User::factory()->create(['estatus' => 'activo']);
    $ruta = NodoComercial::where('tipo', 'ruta')->where('activa', true)->first();

    $this->actingAs($rh)
        ->post(route('administracion.matriz-comercial.apoyo.agregar', $ruta), [
            'colaborador_id' => $apoyo->colaborador_id,
            'tipo' => 'apoyo',
        ])
        ->assertSessionHasNoErrors();

    expect(AsignacionNodoComercial::where('nodo_comercial_id', $ruta->id)->where('activo', true)->count())->toBe(1);

    $this->actingAs($rh)
        ->delete(route('administracion.matriz-comercial.apoyo.quitar', $ruta), [
            'colaborador_id' => $apoyo->colaborador_id,
            'tipo' => 'apoyo',
        ])
        ->assertSessionHasNoErrors();

    expect(AsignacionNodoComercial::where('nodo_comercial_id', $ruta->id)->where('activo', true)->count())->toBe(0);
});

test('dar de baja a un gestor cierra sus asignaciones activas en la matriz', function () {
    $gestor = User::factory()->create(['estatus' => 'activo', 'sucursal_principal_id' => null, 'puesto_id' => null]);
    $ruta = NodoComercial::where('tipo', 'ruta')->where('activa', true)->first();

    app(MatrizComercialService::class)->asignarResponsable($ruta, $gestor->colaborador);
    expect($ruta->fresh()->responsable_colaborador_id)->toBe($gestor->colaborador_id);

    $actor = User::factory()->create();
    app(MovimientoLaboralService::class)->registrarBaja($gestor->colaborador, $actor);

    expect($ruta->fresh()->responsable_colaborador_id)->toBeNull()
        ->and(AsignacionNodoComercial::where('colaborador_id', $gestor->colaborador_id)->where('activo', true)->count())->toBe(0);
});
