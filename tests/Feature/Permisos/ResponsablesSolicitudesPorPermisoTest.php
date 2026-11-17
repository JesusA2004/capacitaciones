<?php

use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
| Etapa 1 · R10: el filtro «Responsable» de Solicitudes sale de permisos
| (revisar + alcance «Todos»), no del nombre del rol.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
});

test('un rol personalizado con revisar y alcance todos aparece como responsable', function () {
    $rh = clUsuario('rh_admin');
    $rol = Role::create(['name' => 'mesa_solicitudes', 'guard_name' => 'web']);
    $rol->givePermissionTo(['solicitudes.ver', 'solicitudes.revisar', 'solicitudes.ver_todos']);
    $revisor = clUsuario('colaborador');
    $revisor->syncRoles(['mesa_solicitudes']);
    $gerente = clUsuario('gerente_sucursal');

    $this->actingAs($rh)->get(route('rh.solicitudes.index'))
        ->assertOk()
        ->assertInertia(function (Assert $p) use ($rh, $revisor, $gerente): void {
            $ids = collect($p->toArray()['props']['opciones']['responsables'])->pluck('id');
            expect($ids)->toContain($rh->id)->toContain($revisor->id)->not->toContain($gerente->id);
        });
});
