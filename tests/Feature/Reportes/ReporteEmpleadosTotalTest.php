<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Reportes\ReportesRhService;
use Database\Seeders\RolesYPermisosSeeder;

/*
 * Reporte "Número de empleados" (empleados_total): el desglose por estatus
 * debe reconciliar con el Total. Sin la fila "En incorporación" los
 * contratados cuya alta sigue en proceso quedaban fuera del desglose y la
 * suma no cuadraba con el Total (colaboradoresVisibles).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

it('reconcilia la suma de las filas de estatus con el Total e incluye En incorporación', function () {
    Colaborador::factory()->count(3)->create(['estatus' => EstadoUsuario::Activo->value]);
    Colaborador::factory()->count(2)->create(['estatus' => EstadoUsuario::Inactivo->value]);
    Colaborador::factory()->create(['estatus' => EstadoUsuario::Suspendido->value]);
    Colaborador::factory()->count(2)->create(['estatus' => EstadoUsuario::EnIncorporacion->value]);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $reporte = app(ReportesRhService::class)->generar('empleados_total', $rh, []);

    $filas = collect($reporte['filas'])->keyBy(0);

    // La fila de "En incorporación" existe, con su etiqueta en español.
    expect($filas->has(EstadoUsuario::EnIncorporacion->etiqueta()))->toBeTrue()
        ->and(EstadoUsuario::EnIncorporacion->etiqueta())->toBe('En incorporación');

    $total = (int) $filas['Total'][1];
    $sumaDesglose = collect($reporte['filas'])
        ->reject(fn (array $fila): bool => $fila[0] === 'Total')
        ->sum(fn (array $fila): int => (int) $fila[1]);

    // La cuenta rh_admin trae su propio Colaborador (UserFactory), por eso
    // no se fija un total exacto: lo que importa es que el desglose cuadre.
    expect($sumaDesglose)->toBe($total)
        ->and($filas[EstadoUsuario::EnIncorporacion->etiqueta()][1])->toBeGreaterThanOrEqual(2);
});
