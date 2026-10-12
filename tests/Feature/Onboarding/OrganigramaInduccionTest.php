<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Capítulo 4 de Inducción Mr. Lana: el organigrama debe salir del catálogo
| REAL de puestos (Fase 3, MiniOrganigramaService), nunca de una imagen fija
| ni de personas hardcodeadas — ver config/organigrama.php 'vistas_induccion'.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('la vista "comercial" del organigrama de inducción trae la rama real de Dirección Comercial, con ocupantes activos', function () {
    $direccionComercial = Puesto::factory()->create(['nombre' => 'Dirección Comercial']);
    $gerenteSucursalPuesto = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'puesto_superior_id' => $direccionComercial->id]);
    $sucursal = Sucursal::factory()->create();
    $gerente = Colaborador::factory()->create(['puesto_id' => $gerenteSucursalPuesto->id, 'sucursal_principal_id' => $sucursal->id, 'estatus' => 'activo']);

    $usuario = clUsuario('colaborador');

    $this->actingAs($usuario)
        ->getJson(route('portal.induccion.organigrama', ['vista' => 'comercial']))
        ->assertOk()
        ->assertJsonPath('data.id', $direccionComercial->id)
        ->assertJsonPath('data.nombre', 'Dirección Comercial')
        ->assertJsonPath('data.hijos.0.nombre', 'Gerente de Sucursal')
        ->assertJsonPath('data.hijos.0.ocupantes.0.nombre', trim($gerente->name.' '.$gerente->apellidos));
});

test('una vista de organigrama que no existe responde 404, nunca una imagen fija de respaldo', function () {
    $usuario = clUsuario('colaborador');

    $this->actingAs($usuario)
        ->getJson(route('portal.induccion.organigrama', ['vista' => 'no_existe']))
        ->assertNotFound();
});

test('la API móvil expone la misma vista de organigrama que la web (mismo service)', function () {
    $direccionGeneral = Puesto::factory()->create(['nombre' => 'Dirección General']);
    Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'puesto_superior_id' => $direccionGeneral->id]);

    $usuario = clUsuario('colaborador');

    Laravel\Sanctum\Sanctum::actingAs($usuario);
    $this->getJson('/api/v1/colaborador/induccion/organigrama/corporativo')
        ->assertOk()
        ->assertJsonPath('data.id', $direccionGeneral->id)
        ->assertJsonPath('data.nombre', 'Dirección General')
        ->assertJsonPath('data.hijos.0.nombre', 'Dirección Comercial');
});
