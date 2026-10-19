<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * HUB «Importar datos» (Administración → Configuración): índice que agrupa las
 * importaciones administrativas existentes. Cada tarjeta se gatea por su
 * permiso real; el hub exige al menos un permiso de importación. Aquí se
 * prueba el filtrado por permiso y el bloqueo total, no los flujos de
 * importación en sí (cada uno tiene su propia suite).
 */
beforeEach(function () {
    // El hub es una página nueva aún no presente en el manifest de Vite
    // precompilado; sin build, la primera carga (HTML) fallaría al resolver
    // el chunk. withoutVite() stubbea esa resolución sin tocar la lógica.
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
});

test('el hub carga y muestra solo las tarjetas con permiso', function () {
    $usuario = User::factory()->create();
    $usuario->givePermissionTo('rotacion.cargar');

    $this->actingAs($usuario)
        ->get(route('administracion.importar-datos.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Administracion/ImportarDatos/Index')
            ->where('puede.rotacion', true)
            ->where('puede.migracion', false)
            ->where('puede.headcount', false)
            ->where('puede.salarios_vacaciones', false)
            ->where('puede.perfiles_puesto', false));
});

test('cada permiso de importación habilita su propia tarjeta', function () {
    $usuario = User::factory()->create();
    $usuario->givePermissionTo('expedientes.migrar');

    $this->actingAs($usuario)
        ->get(route('administracion.importar-datos.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('puede.migracion', true)
            ->where('puede.rotacion', false)
            ->where('puede.salarios_vacaciones', false));
});

test('sin ningún permiso de importación el hub responde 403', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->get(route('administracion.importar-datos.index'))
        ->assertForbidden();
});
