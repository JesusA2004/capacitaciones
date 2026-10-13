<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Láminas del organigrama como el PDF de Inducción (hojas 17-19): cada área
| y puesto muestra a sus personas REALES con nombre completo; un puesto sin
| nadie queda vacante (nunca un nombre inventado) y Corporativo no aparece
| como sucursal.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('las láminas muestran a las personas reales de cada área y sucursal', function () {
    $corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'es_corporativo' => true, 'activo' => true]);
    $tula = Sucursal::factory()->create(['nombre' => 'Tula', 'es_corporativo' => false, 'activo' => true]);
    $sistemas = Puesto::factory()->create(['nombre' => 'Responsable de Sistemas']);
    $regional = Puesto::factory()->create(['nombre' => 'Gerente Regional Q3']);
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Martínez Ruiz', 'puesto_id' => $sistemas->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Mónica', 'apellidos' => 'Hernández Ortega', 'puesto_id' => $regional->id, 'sucursal_principal_id' => $tula->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Pérez Gómez', 'puesto_id' => $gerente->id, 'sucursal_principal_id' => $tula->id, 'estatus' => 'activo']);

    $laminas = $this->actingAs(clUsuario('rh_admin'))->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk()->viewData('page')['props']['laminas'];

    $areas = collect($laminas['corporativo']['areas'])->keyBy('sigla');
    $regionalComercial = collect($laminas['comercial']['columnas'])->firstWhere('titulo', 'Regional Comercial');
    $sucursales = collect($laminas['sucursal']['sucursales'])->keyBy('nombre');

    expect($areas)->toHaveKeys(['DC', 'RH', 'CT', 'JE', 'SI'])
        ->and($areas['SI']['personas'][0]['nombre'])->toBe('Ana Martínez Ruiz')
        ->and($areas['JE']['personas'])->toBe([])
        // Nadie ocupa Dirección General: se usa el titular del PDF oficial.
        ->and($laminas['corporativo']['direccion_general']['personas'][0]['nombre'])->toBe('LIC. ZURIEL ÁVILA MARTÍNEZ')
        // La regional aparece con su sede.
        ->and($regionalComercial['personas'][0])->toMatchArray(['nombre' => 'Mónica Hernández Ortega', 'sucursal' => 'Tula'])
        ->and($sucursales)->not->toHaveKey('Corporativo')
        ->and($sucursales['Tula']['gerente'][0]['nombre'])->toBe('Luis Pérez Gómez')
        ->and($sucursales['Tula']['subgerente'])->toBe([]);
});
