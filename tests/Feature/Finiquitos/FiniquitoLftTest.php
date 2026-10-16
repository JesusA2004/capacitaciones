<?php

use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Finiquitos\FiniquitoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
| Finiquito conforme a la LFT: aguinaldo proporcional a los días trabajados
| en el AÑO CALENDARIO (art. 87) y vacaciones = saldo pendiente + parte
| proporcional del año de servicio en curso (art. 79), con prima vacacional
| sobre esos mismos días.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'America/Mexico_City'));
    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
    $this->servicio = app(FiniquitoService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function solicitudBajaLft(string $fechaIngreso): SolicitudInterna
{
    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => $fechaIngreso]);

    return SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'objetivo_colaborador_id' => $colaborador->id,
        'fecha_efectiva' => '2026-10-16',
        'tipo_baja' => 'renuncia',
    ]);
}

test('aguinaldo proporcional usa los días trabajados en el año calendario (caso real: ingreso 10 jul 2025, baja 16 oct 2026)', function () {
    $finiquito = $this->servicio->calcular(solicitudBajaLft('2025-07-10'), $this->rh, 15000);

    // 1 ene 2026 → 16 oct 2026, inclusive = 289 días; 15 × 289 / 365 = 11.88 días.
    expect($finiquito->dias_trabajados_anio)->toBe(289)
        ->and((float) $finiquito->sueldo_diario)->toBe(500.0)
        ->and((float) $finiquito->aguinaldo_proporcional)->toBe(round(15 * 289 / 365 * 500, 2));

    $aguinaldo = collect($this->servicio->desglose($finiquito))->firstWhere('concepto_clave', 'aguinaldo_proporcional');

    expect($aguinaldo['dias'])->toBe(11.88)
        ->and($aguinaldo['importe'])->toBe(5938.36);
});

test('vacaciones = saldo pendiente + proporcionales del año de servicio en curso, y la prima va sobre los mismos días', function () {
    $finiquito = $this->servicio->calcular(solicitudBajaLft('2025-07-10'), $this->rh, 15000);

    // Saldo del año cumplido (1 año → 12 días, sin usar) + proporcional del
    // 2.º año (14 días × 99 días desde el 10 jul 2026 / 365 = 3.80).
    expect($finiquito->vacaciones_pendientes)->toBe(12)
        ->and($finiquito->dias_trabajados_periodo)->toBe(99)
        ->and((float) $finiquito->vacaciones_proporcionales)->toBe(3.8)
        ->and((float) $finiquito->vacaciones_pendientes_pago)->toBe(round(15.8 * 500, 2))
        ->and((float) $finiquito->prima_vacacional)->toBe(round(15.8 * 500 * 0.25, 2));

    $renglones = collect($this->servicio->desglose($finiquito))->keyBy('concepto_clave');

    expect($renglones['vacaciones_proporcionales']['dias'])->toBe(15.8)
        ->and($renglones['vacaciones_proporcionales']['observaciones'])->toContain('proporcionales del año en curso')
        ->and($renglones['prima_vacacional']['dias'])->toBe(15.8);
});

test('en el primer año el aguinaldo cuenta desde el ingreso y no hay saldo, solo vacaciones proporcionales', function () {
    $finiquito = $this->servicio->calcular(solicitudBajaLft('2026-03-01'), $this->rh, 15000);

    // 1 mar → 16 oct 2026, inclusive = 230 días.
    expect($finiquito->dias_trabajados_anio)->toBe(230)
        ->and((float) $finiquito->aguinaldo_proporcional)->toBe(round(15 * 230 / 365 * 500, 2))
        ->and($finiquito->vacaciones_pendientes)->toBe(0)
        ->and((float) $finiquito->vacaciones_proporcionales)->toBe(round(12 * 230 / 365, 2));
});

test('los ajustes de RH a un concepto automático se conservan al recalcular', function () {
    $finiquito = $this->servicio->calcular(solicitudBajaLft('2025-07-10'), $this->rh, 15000);
    $this->servicio->ajustarConceptoAutomatico($finiquito, 'aguinaldo_proporcional', 6000, 'Redondeo autorizado', $this->rh);

    $finiquito = $this->servicio->recalcular($finiquito->refresh(), $this->rh, 15000);
    $aguinaldo = collect($this->servicio->desglose($finiquito))->firstWhere('concepto_clave', 'aguinaldo_proporcional');

    expect((float) $finiquito->aguinaldo_proporcional)->toBe(5938.36)
        ->and($aguinaldo['importe'])->toBe(6000.0)
        ->and($aguinaldo['ajustado'])->toBeTrue();
});
