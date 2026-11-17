<?php

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Etapa 1 · R4: Administración → Usuarios solo abre cuenta a colaboradores
| vigentes y dentro del alcance de quien la crea (CuentaUsuarioService).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('no se abre cuenta a un colaborador dado de baja ni fuera del alcance', function () {
    $estructura = clEstructura();
    $a = $estructura['sucursal'];
    $b = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id]);

    $rh = clUsuario('rh_admin');
    $deBaja = Colaborador::factory()->create(['estatus' => 'inactivo', 'fecha_baja' => now()->subDay()]);

    $this->actingAs($rh)->post(route('administracion.usuarios.store'), ['colaborador_id' => $deBaja->id, 'roles' => []])
        ->assertSessionHasErrors('colaborador_id');

    $gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $a->id]);
    $gerente->givePermissionTo(['usuarios.ver', 'usuarios.crear']);
    $enB = Colaborador::factory()->create(['sucursal_principal_id' => $b->id]);
    $enA = Colaborador::factory()->create(['sucursal_principal_id' => $a->id]);

    $this->actingAs($gerente)->post(route('administracion.usuarios.store'), ['colaborador_id' => $enB->id, 'roles' => []])
        ->assertSessionHasErrors('colaborador_id');
    $this->actingAs($gerente)->post(route('administracion.usuarios.store'), ['colaborador_id' => $enA->id, 'roles' => []])
        ->assertSessionHasNoErrors();

    expect(User::query()->whereIn('colaborador_id', [$deBaja->id, $enB->id])->exists())->toBeFalse()
        ->and(User::query()->where('colaborador_id', $enA->id)->exists())->toBeTrue();
});
