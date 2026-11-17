<?php

use App\Models\Colaborador;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Etapa 1 · R8: una sucursal o un departamento que todavía tiene
| colaboradores no se elimina (igual que Empresas y Puestos).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('no se elimina una sucursal ni un departamento con colaboradores', function () {
    $estructura = clEstructura();
    Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'departamento_id' => $estructura['departamento']->id]);
    $admin = clUsuario('super_admin');

    $this->actingAs($admin)->delete(route('administracion.sucursales.destroy', $estructura['sucursal']))
        ->assertRedirect()->assertSessionHas('toast.type', 'error');
    $this->actingAs($admin)->delete(route('administracion.departamentos.destroy', $estructura['departamento']))
        ->assertRedirect()->assertSessionHas('toast.type', 'error');

    expect($estructura['sucursal']->fresh()->trashed())->toBeFalse()
        ->and($estructura['departamento']->fresh()->trashed())->toBeFalse();
});
