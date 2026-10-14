<?php

use App\Models\AjusteHistoricoVacaciones;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Vacaciones\VacacionesService;

/**
 * Caso real (cierre de producto): Luz Elena, 4 años de antigüedad, 18 días
 * corresponden, 0 usados dentro de PEOPLE, -9 de ajuste histórico importado.
 * El saldo (9 disponibles) siempre fue correcto; lo que faltaba era separar
 * "usados" en PEOPLE vs histórico para que la UI no mostrara "Usados: 0"
 * de forma confusa junto a "Disponibles: 9".
 */
test('un ajuste historico negativo se reporta como usado historico, no se mezcla con lo usado en PEOPLE', function () {
    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => now()->subYears(4)]);
    User::factory()->create(['colaborador_id' => $colaborador->id]);

    AjusteHistoricoVacaciones::factory()->create([
        'colaborador_id' => $colaborador->id,
        'dias' => -9,
        'fecha_corte' => now()->toDateString(),
    ]);

    $saldo = app(VacacionesService::class)->saldoColaborador($colaborador);

    expect($saldo['dias_usados_people'])->toBe(0)
        ->and($saldo['dias_usados_historicos'])->toBe(9.0)
        ->and($saldo['dias_usados_total'])->toBe(9.0)
        ->and($saldo['ajustes_a_favor'])->toBe(0.0)
        ->and($saldo['dias_disponibles'])->toBe($saldo['dias_generados'] - 9);
});

test('un ajuste historico positivo se reporta como ajuste a favor, nunca como usado', function () {
    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => now()->subYears(2)]);
    User::factory()->create(['colaborador_id' => $colaborador->id]);

    AjusteHistoricoVacaciones::factory()->create([
        'colaborador_id' => $colaborador->id,
        'dias' => 3,
        'fecha_corte' => now()->toDateString(),
    ]);

    $saldo = app(VacacionesService::class)->saldoColaborador($colaborador);

    expect($saldo['ajustes_a_favor'])->toBe(3.0)
        ->and($saldo['dias_usados_historicos'])->toBe(0.0)
        ->and($saldo['dias_usados_total'])->toBe(0.0)
        ->and($saldo['dias_disponibles'])->toBe($saldo['dias_generados'] + 3);
});

test('antes del primer año nunca se acreditan ajustes historicos (aunque existan)', function () {
    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => now()->subMonths(6)]);
    User::factory()->create(['colaborador_id' => $colaborador->id]);

    AjusteHistoricoVacaciones::factory()->create([
        'colaborador_id' => $colaborador->id,
        'dias' => 5,
        'fecha_corte' => now()->toDateString(),
    ]);

    $saldo = app(VacacionesService::class)->saldoColaborador($colaborador);

    expect($saldo['puede_solicitar'])->toBeFalse()
        ->and($saldo['ajustes_a_favor'])->toBe(0.0)
        ->and($saldo['dias_disponibles'])->toBe(0);
});
