<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\AjusteHistoricoVacaciones;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\FechasSolicitudService;
use App\Services\Vacaciones\VacacionesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
| Regla definitiva (2026-10-15): sin prorrateo. 0 días hasta el día EXACTO
| del aniversario de fecha_ingreso, calculado con la fecha calendario de
| America/Mexico_City. Caso real: ingreso 01/12/2025 mostraba 12 días en
| octubre de 2026.
*/

afterEach(fn () => Carbon::setTestNow());

function vacAniversarioColaborador(string $ingreso): Colaborador
{
    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => $ingreso, 'estatus' => 'activo']);
    User::factory()->create(['colaborador_id' => $colaborador->id]);

    return $colaborador->refresh();
}

test('ingreso 2025-12-01: 0 días el 2026-10-15 y el 2026-11-30, 12 días el 2026-12-01', function (string $hoyMexico, int $generados, bool $puede) {
    // Mediodía en México para no depender de la hora del runner.
    Carbon::setTestNow(Carbon::parse($hoyMexico.' 12:00:00', 'America/Mexico_City'));
    $colaborador = vacAniversarioColaborador('2025-12-01');

    $saldo = app(VacacionesService::class)->saldoColaborador($colaborador);

    expect($saldo['dias_generados'])->toBe($generados)
        ->and($saldo['dias_disponibles'])->toBe($generados)
        ->and($saldo['puede_solicitar'])->toBe($puede)
        ->and($saldo['fecha_ingreso'])->toBe('2025-12-01')
        ->and($saldo['primer_aniversario'])->toBe('2026-12-01');
})->with([
    'octubre 2026' => ['2026-10-15', 0, false],
    'víspera del aniversario' => ['2026-11-30', 0, false],
    'día del aniversario' => ['2026-12-01', 12, true],
]);

test('antes del primer año informa meses, fecha de disponibilidad y el motivo', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/Mexico_City'));
    $saldo = app(VacacionesService::class)->saldoColaborador(vacAniversarioColaborador('2025-12-01'));

    expect($saldo['antiguedad_anios'])->toBe(0)
        ->and($saldo['antiguedad_meses'])->toBe(10)
        ->and($saldo['antiguedad_texto'])->toBe('10 meses · Aún no cumple un año')
        ->and($saldo['fecha_disponible_desde'])->toBe('2026-12-01')
        ->and($saldo['motivo_no_disponible'])->toContain('01/12/2026');
});

test('después del aniversario la vigencia corre de aniversario a aniversario', function () {
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'America/Mexico_City'));
    $saldo = app(VacacionesService::class)->saldoColaborador(vacAniversarioColaborador('2025-12-01'));

    expect($saldo['antiguedad_texto'])->toBe('1 año')
        ->and($saldo['vigencia_inicio'])->toBe('2026-12-01')
        ->and($saldo['vigencia_fin'])->toBe('2027-11-30');
});

test('la noche del 30 de noviembre en México (ya 1 de diciembre en UTC) todavía no hay vacaciones', function () {
    // 23:30 en México = 05:30 UTC del día siguiente.
    Carbon::setTestNow(Carbon::parse('2026-11-30 23:30:00', 'America/Mexico_City'));
    $saldo = app(VacacionesService::class)->saldoColaborador(vacAniversarioColaborador('2025-12-01'));

    expect($saldo['dias_generados'])->toBe(0)->and($saldo['puede_solicitar'])->toBeFalse();
});

test('segundo aniversario aplica la tabla del segundo año (14 días)', function () {
    Carbon::setTestNow(Carbon::parse('2027-03-05 12:00:00', 'America/Mexico_City'));
    $saldo = app(VacacionesService::class)->saldoColaborador(vacAniversarioColaborador('2025-03-05'));

    expect($saldo['antiguedad_anios'])->toBe(2)->and($saldo['dias_generados'])->toBe(14);
});

test('ingreso 05/03/2025: 0 días el 04/03/2026 y 12 el 05/03/2026', function () {
    $servicio = app(VacacionesService::class);
    $colaborador = vacAniversarioColaborador('2025-03-05');

    Carbon::setTestNow(Carbon::parse('2026-03-04 12:00:00', 'America/Mexico_City'));
    expect($servicio->saldoColaborador($colaborador)['dias_generados'])->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-03-05 12:00:00', 'America/Mexico_City'));
    expect($servicio->saldoColaborador($colaborador)['dias_generados'])->toBe(12);
});

test('sin fecha de ingreso el saldo dice que está pendiente de validación', function () {
    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => null]);

    $saldo = app(VacacionesService::class)->saldoColaborador($colaborador);

    expect($saldo['dias_generados'])->toBe(0)
        ->and($saldo['motivo_no_disponible'])->toBe('Fecha de ingreso pendiente de validación por RH.');
});

test('históricos, ajustes positivos, aprobadas y pendientes se descuentan dentro de la vigencia', function () {
    Carbon::setTestNow(Carbon::parse('2027-01-15 12:00:00', 'America/Mexico_City'));
    $colaborador = vacAniversarioColaborador('2025-12-01');
    $usuario = $colaborador->user;

    AjusteHistoricoVacaciones::factory()->create(['colaborador_id' => $colaborador->id, 'dias' => -2, 'fecha_corte' => '2026-12-10']);
    AjusteHistoricoVacaciones::factory()->create(['colaborador_id' => $colaborador->id, 'dias' => 1, 'fecha_corte' => '2026-12-11']);

    $base = ['user_id' => $usuario?->id, 'colaborador_id' => $colaborador->id, 'tipo' => TipoSolicitudInterna::Vacaciones];
    SolicitudInterna::factory()->create([...$base, 'estado' => EstadoSolicitudInterna::Aprobada, 'fecha_inicio' => '2027-01-05', 'fecha_fin' => '2027-01-07', 'dias_solicitados' => 3]);
    SolicitudInterna::factory()->create([...$base, 'estado' => EstadoSolicitudInterna::Enviada, 'fecha_inicio' => '2027-02-01', 'fecha_fin' => '2027-02-02', 'dias_solicitados' => 2]);

    $saldo = app(VacacionesService::class)->saldoColaborador($colaborador);

    // 12 generados + 1 a favor − 2 históricos − 3 aprobados − 2 pendientes.
    expect($saldo['dias_usados_historicos'])->toBe(2.0)
        ->and($saldo['ajustes_a_favor'])->toBe(1.0)
        ->and($saldo['dias_usados'])->toBe(3)
        ->and($saldo['dias_en_solicitud'])->toBe(2)
        ->and($saldo['dias_disponibles'])->toBe(6);
});

test('el sábado cuenta como día de vacaciones y el domingo no es seleccionable', function () {
    $fechas = app(FechasSolicitudService::class);

    expect($fechas->esDiaComputableVacaciones(CarbonImmutable::parse('2026-10-17')))->toBeTrue()   // sábado
        ->and($fechas->esDiaComputableVacaciones(CarbonImmutable::parse('2026-10-18')))->toBeFalse(); // domingo
});
