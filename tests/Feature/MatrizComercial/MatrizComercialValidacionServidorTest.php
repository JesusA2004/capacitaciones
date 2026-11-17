<?php

use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\User;
use Database\Seeders\MatrizComercialSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;

/*
| Etapa 1 · R3: la matriz comercial valida en el servidor (no solo en el
| selector) que el gestor sea un colaborador activo, y registra quién hizo
| el cambio en la bitácora.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(SucursalSeeder::class);
    $this->seed(MatrizComercialSeeder::class);
});

test('no se asigna como gestor a un colaborador inactivo y el cambio válido queda en bitácora', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $ruta = NodoComercial::where('tipo', 'ruta')->where('activa', true)->firstOrFail();

    $inactivo = Colaborador::factory()->create(['estatus' => 'inactivo']);
    $this->actingAs($rh)
        ->put(route('administracion.matriz-comercial.responsable', $ruta), ['responsable_colaborador_id' => $inactivo->id])
        ->assertSessionHasErrors('responsable_colaborador_id');
    expect($ruta->fresh()->responsable_colaborador_id)->toBeNull();

    $activo = Colaborador::factory()->create(['estatus' => 'activo']);
    $this->actingAs($rh)
        ->put(route('administracion.matriz-comercial.responsable', $ruta), ['responsable_colaborador_id' => $activo->id])
        ->assertSessionHasNoErrors();

    expect($ruta->fresh()->responsable_colaborador_id)->toBe($activo->id);
    $this->assertDatabaseHas('activity_log', ['event' => 'matriz_comercial.gestor_asignado', 'causer_id' => $rh->id, 'subject_id' => $ruta->id]);
});
