<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Directorio de la empresa: cualquier colaborador autenticado puede
| buscar/filtrar por puesto y sucursal (directorio GLOBAL). El correo
| corporativo no todos lo tienen (solo administrativos/gerenciales) — una
| fila sin correo se muestra igual, nunca se inventa uno.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('cualquier colaborador autenticado puede ver el directorio y filtrar por puesto', function () {
    $sucursal = Sucursal::factory()->create(['activo' => true]);
    $gerentePuesto = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    $gestorPuesto = Puesto::factory()->create(['nombre' => 'Gestor', 'activo' => true]);

    Colaborador::factory()->create([
        'name' => 'María', 'apellidos' => 'López',
        'puesto_id' => $gerentePuesto->id, 'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo', 'correo_corporativo' => 'maria.lopez@mrlana.test',
    ]);
    Colaborador::factory()->create([
        'name' => 'Pedro', 'apellidos' => 'Ramírez',
        'puesto_id' => $gestorPuesto->id, 'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo', 'correo_corporativo' => null,
    ]);

    $usuario = clUsuario('colaborador');

    $respuesta = $this->actingAs($usuario)->get(route('directorio.index'))->assertOk();
    $respuesta->assertInertia(fn ($page) => $page->where(
        'colaboradores.data',
        fn ($data) => collect($data)->pluck('nombre')->contains('María López')
            && collect($data)->pluck('nombre')->contains('Pedro Ramírez'),
    ));

    $this->actingAs($usuario)
        ->get(route('directorio.index', ['puesto_id' => $gerentePuesto->id]))
        ->assertInertia(fn ($page) => $page->where(
            'colaboradores.data',
            fn ($data) => count($data) === 1
                && $data[0]['nombre'] === 'María López'
                && $data[0]['correo_corporativo'] === 'maria.lopez@mrlana.test',
        ));
});

test('un colaborador sin correo corporativo aparece en el directorio sin correo inventado', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo', 'correo_corporativo' => null]);
    $usuario = clUsuario('colaborador');

    $this->actingAs($usuario)->get(route('directorio.index'))
        ->assertInertia(fn ($page) => $page->where(
            'colaboradores.data',
            fn ($data) => collect($data)->firstWhere('id', $colaborador->id)['correo_corporativo'] === null,
        ));
});
