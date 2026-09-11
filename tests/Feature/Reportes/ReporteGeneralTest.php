<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

it('carga el hub de reportes y ambos exportes para un usuario con permiso', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)->get('/reportes')->assertOk();
    $this->actingAs($usuario)->get('/reportes/exportar/excel')->assertOk();
    $this->actingAs($usuario)->get('/reportes/exportar/pdf')->assertOk();
});

it('bloquea el hub de reportes sin permiso', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)->get('/reportes')->assertForbidden();
});
