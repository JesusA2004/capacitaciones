<?php

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Laravel\Sanctum\Sanctum;

/*
| Directorio (cierre 2026-10-15): nombre, puesto, área y sucursal para
| todos; correo/teléfono corporativos solo con permiso
| directorio.contactos.ver_todos o siendo personal corporativo. Un
| colaborador operativo solo ve el contacto de su jefe directo. Web y API
| reciben el MISMO dato: el backend no manda lo que no se puede ver.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->sucursal = Sucursal::factory()->create(['es_corporativo' => false]);
    $this->corporativo = Sucursal::factory()->create(['es_corporativo' => true]);

    $this->jefe = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'correo_corporativo' => 'jefe@mrlana.test', 'telefono_corporativo' => '5551110000']);
    $this->otro = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'correo_corporativo' => 'otro@mrlana.test', 'telefono_corporativo' => '5552220000']);

    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'jefe_id' => $this->jefe->id]);
    $this->operativo = User::factory()->create(['colaborador_id' => $persona->id]);
    $this->operativo->assignRole('colaborador');
});

function dpBuscar(array $lista, int $id): array
{
    return collect($lista)->firstWhere('id', $id);
}

test('un colaborador operativo solo recibe el contacto de su jefe directo (web y API)', function () {
    $web = $this->actingAs($this->operativo)->get(route('directorio.index'))->assertOk()->viewData('page')['props']['colaboradores'];

    expect(dpBuscar($web, $this->jefe->id)['correo_corporativo'])->toBe('jefe@mrlana.test')
        ->and(dpBuscar($web, $this->jefe->id)['es_mi_jefe'])->toBeTrue()
        ->and(dpBuscar($web, $this->otro->id)['correo_corporativo'])->toBeNull()
        ->and(dpBuscar($web, $this->otro->id)['telefono_corporativo'])->toBeNull()
        ->and(dpBuscar($web, $this->otro->id)['nombre'])->toBe($this->otro->nombreCompleto());

    Sanctum::actingAs($this->operativo);
    $api = $this->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data');

    expect(dpBuscar($api, $this->jefe->id)['telefono_corporativo'])->toBe('5551110000')
        ->and(dpBuscar($api, $this->otro->id)['correo_corporativo'])->toBeNull()
        ->and(dpBuscar($api, $this->otro->id)['nombre'])->toBe($this->otro->nombreCompleto());
});

test('personal corporativo ve todos los contactos', function () {
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->corporativo->id]);
    $corporativo = User::factory()->create(['colaborador_id' => $persona->id]);
    $corporativo->assignRole('colaborador');

    Sanctum::actingAs($corporativo);
    $api = $this->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data');

    expect(dpBuscar($api, $this->otro->id)['correo_corporativo'])->toBe('otro@mrlana.test');
});

test('RH, Sistemas y super_admin ven todos los contactos por permiso', function (string $rol) {
    $usuario = clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]);

    Sanctum::actingAs($usuario);
    $api = $this->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data');

    expect(dpBuscar($api, $this->otro->id)['correo_corporativo'])->toBe('otro@mrlana.test');
})->with(['rh_admin', 'sistemas', 'super_admin']);

test('«solo con correo» se ignora para quien no ve todos los contactos (no permite inferir quién tiene correo)', function () {
    $sinCorreo = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'correo_corporativo' => null, 'telefono_corporativo' => null]);

    Sanctum::actingAs($this->operativo);
    $ids = collect($this->getJson('/api/v1/directorio?per_page=50&solo_con_correo=1')->assertOk()->json('data'))->pluck('id');

    // El filtro no se aplica: la persona sin correo sigue en la lista.
    expect($ids)->toContain($sinCorreo->id)
        ->and($ids)->toContain($this->otro->id);

    Sanctum::actingAs(clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]));
    $ids = collect($this->getJson('/api/v1/directorio?per_page=50&solo_con_correo=1')->assertOk()->json('data'))->pluck('id');

    // Con permiso de ver todos los contactos, el filtro sí aplica.
    expect($ids)->not->toContain($sinCorreo->id)
        ->and($ids)->toContain($this->otro->id);
});
