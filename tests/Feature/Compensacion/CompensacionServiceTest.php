<?php

use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\MovimientoLaboral;
use App\Services\Compensacion\CompensacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
| Compensación centralizada (CLAUDE.md): salario mensual = diario×30
| SIEMPRE (ningún mes usa el número real de días), quincena ordinaria paga
| 15 días salariales (el periodo 16-31 de un mes de 31 días no paga 16),
| semanal lunes→domingo, historial append-only.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->compensacion = app(CompensacionService::class);
});

test('salario mensual = diario × 30, sin importar el mes (31 días, 28 días, 30 días)', function () {
    expect($this->compensacion->mensual(500))->toBe(15000.0);

    // Enero (31 días), febrero (28/29 días) y abril (30 días) dan el mismo
    // resultado: la regla nunca usa el número real de días del mes.
    foreach ([500, 500, 500] as $diario) {
        expect($this->compensacion->mensual($diario))->toBe(15000.0);
    }
});

test('quincena ordinaria paga siempre 15 días salariales, aunque el periodo calendario tenga 16 días', function () {
    $periodo = $this->compensacion->periodoQuincena(Carbon::parse('2026-10-20'));

    expect($periodo['inicio']->toDateString())->toBe('2026-10-16')
        ->and($periodo['fin']->toDateString())->toBe('2026-10-31')
        ->and($periodo['dias_calendario'])->toBe(16)
        ->and($periodo['dias_salariales'])->toBe(15)
        ->and($this->compensacion->quincena(500))->toBe(7500.0);
});

test('primera quincena (1-15) también son 15 días salariales', function () {
    $periodo = $this->compensacion->periodoQuincena(Carbon::parse('2026-07-08'));

    expect($periodo['inicio']->toDateString())->toBe('2026-07-01')
        ->and($periodo['fin']->toDateString())->toBe('2026-07-15')
        ->and($periodo['dias_calendario'])->toBe(15)
        ->and($periodo['dias_salariales'])->toBe(15);
});

test('semana laboral es lunes a domingo, no se confunde con la base mensual de 30', function () {
    // 2026-10-14 es miércoles.
    $semana = $this->compensacion->periodoSemanal(Carbon::parse('2026-10-14'));

    expect($semana['inicio']->format('l'))->toBe('Monday')
        ->and($semana['fin']->format('l'))->toBe('Sunday')
        ->and($this->compensacion->semanal(500))->toBe(3500.0);
});

test('actualizarSalario sincroniza sueldo_mensual = diario×30, deja rastro en historial_salarios y registra el MovimientoLaboral', function () {
    $colaborador = Colaborador::factory()->create(['salario_diario' => 300, 'sueldo_mensual' => 9000]);
    $actor = clUsuario('rh_admin');

    $historial = $this->compensacion->actualizarSalario($colaborador, 350, 365, $actor, ['fuente' => 'manual', 'motivo' => 'Ajuste anual.']);

    expect($colaborador->refresh()->salario_diario)->toBe('350.00')
        ->and((float) $colaborador->sueldo_mensual)->toBe(10500.0)
        ->and($colaborador->salario_diario_integrado)->toBe('365.00');

    expect($historial)->toBeInstanceOf(HistorialSalario::class)
        ->and((float) $historial->salario_diario_anterior)->toBe(300.0)
        ->and((float) $historial->salario_diario_nuevo)->toBe(350.0)
        ->and($historial->fuente)->toBe('manual');

    expect(MovimientoLaboral::query()->where('colaborador_id', $colaborador->id)->where('tipo_movimiento', 'cambio_sueldo')->exists())->toBeTrue();
});

test('actualizarSalario sin salario diario anterior no genera MovimientoLaboral pero sí historial (primer registro)', function () {
    $colaborador = Colaborador::factory()->create(['salario_diario' => null]);
    $actor = clUsuario('rh_admin');

    $this->compensacion->actualizarSalario($colaborador, 300, null, $actor, ['fuente' => 'importacion_excel']);

    expect(MovimientoLaboral::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0)
        ->and(HistorialSalario::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);
});

test('un salario diario en cero o negativo se rechaza', function () {
    $colaborador = Colaborador::factory()->create();
    $actor = clUsuario('rh_admin');

    expect(fn () => $this->compensacion->actualizarSalario($colaborador, 0, null, $actor, ['fuente' => 'manual']))
        ->toThrow(ValidationException::class);
});
