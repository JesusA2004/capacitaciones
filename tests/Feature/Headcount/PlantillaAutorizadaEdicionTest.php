<?php

use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Cuernavaca']);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor de prueba']);
});

test('RH captura la plantilla autorizada y queda el histórico de quién la cambió', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)
        ->put(route('administracion.sucursales.plantilla.update', [$this->sucursal, $this->puesto]), [
            'plantilla_autorizada' => 5,
            'motivo' => 'Apertura de rutas nuevas',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($rh)
        ->put(route('administracion.sucursales.plantilla.update', [$this->sucursal, $this->puesto]), [
            'plantilla_autorizada' => 7,
            'motivo' => 'Ajuste autorizado por dirección',
        ])
        ->assertSessionHasNoErrors();

    expect(HeadcountTarget::query()->where('sucursal_id', $this->sucursal->id)->where('puesto_id', $this->puesto->id)->value('plantilla_autorizada'))->toBe(7);

    $historial = HeadcountTargetHistorial::query()->orderBy('id')->get();
    expect($historial)->toHaveCount(2)
        ->and($historial[0]->valor_anterior)->toBeNull()
        ->and($historial[0]->valor_nuevo)->toBe(5)
        ->and($historial[1]->valor_anterior)->toBe(5)
        ->and($historial[1]->valor_nuevo)->toBe(7)
        ->and($historial[1]->user_id)->toBe($rh->id)
        ->and($historial[1]->motivo)->toBe('Ajuste autorizado por dirección');

    $this->actingAs($rh)
        ->get(route('administracion.sucursales.show', $this->sucursal))
        ->assertInertia(fn ($page) => $page
            ->where('puedeEditarPlantilla', true)
            ->has('historialPlantilla', 2)
            ->where('totales.plantilla_autorizada', 7));
});

test('quien no es RH no puede mover la plantilla autorizada aunque administre sucursales', function () {
    $admin = clUsuario('administrador_capacitacion');

    $this->actingAs($admin)
        ->put(route('administracion.sucursales.plantilla.update', [$this->sucursal, $this->puesto]), [
            'plantilla_autorizada' => 5,
            'motivo' => 'Intento sin permiso',
        ])
        ->assertForbidden();

    expect(HeadcountTarget::query()->count())->toBe(0)
        ->and(HeadcountTargetHistorial::query()->count())->toBe(0);

    $this->actingAs($admin)
        ->get(route('administracion.sucursales.show', $this->sucursal))
        ->assertInertia(fn ($page) => $page->where('puedeEditarPlantilla', false));
});

test('el motivo del cambio es obligatorio', function () {
    $this->actingAs(clUsuario('rh_admin'))
        ->put(route('administracion.sucursales.plantilla.update', [$this->sucursal, $this->puesto]), [
            'plantilla_autorizada' => 3,
        ])
        ->assertSessionHasErrors('motivo');
});
