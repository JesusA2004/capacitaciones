<?php

use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoUsuario;
use App\Enums\PeriodicidadNomina;
use App\Jobs\SendExpoPushJob;
use App\Models\Colaborador;
use App\Models\NominaLote;
use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\ReciboNomina;
use App\Services\Nomina\LoteNominaService;
use App\Services\Nomina\PrestamoService;
use App\Services\Nomina\ReciboNominaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * El descuento de un préstamo se registra SOLO al emitir el recibo (nunca al
 * preparar/recalcular/cancelar un borrador) y de forma idempotente; cancelar
 * un recibo ya emitido lo compensa con un movimiento `reverso_nomina`.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    Bus::fake([SendExpoPushJob::class]);
    $this->rh = clUsuario('rh_admin');
    $this->rh->colaborador?->update(['sueldo_mensual' => null]);
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01', 'rfc' => 'XAXX010101000', 'curp' => 'XEXX010101HNEXXXA4', 'nss' => '12345678901']);
    $this->prestamo = Prestamo::factory()->create(['colaborador_id' => $this->colaborador->id, 'monto_original' => 5000, 'saldo' => 5000, 'estado' => 'activo']);
    $this->recibos = app(ReciboNominaService::class);
    $this->lotes = app(LoteNominaService::class);
});

function pdrConceptos(Prestamo $prestamo, float $descuento = 500, bool $clasificado = true): array
{
    $linea = ['tipo' => 'deduccion', 'concepto' => 'Descuento préstamo', 'importe' => $descuento];

    if ($clasificado) {
        $linea += ['clasificacion' => 'prestamo', 'prestamo_id' => $prestamo->id];
    }

    return [
        ['tipo' => 'percepcion', 'concepto' => 'Sueldo semanal', 'importe' => 3500],
        $linea,
    ];
}

function pdrBorrador(Colaborador $colaborador, Prestamo $prestamo, $rh): ReciboNomina
{
    return app(ReciboNominaService::class)->generar($colaborador, [
        'periodo_inicio' => '2026-09-14',
        'periodo_fin' => '2026-09-20',
        'fecha_pago' => '2026-09-21',
        'conceptos' => pdrConceptos($prestamo),
    ], $rh, borrador: true);
}

function pdrLoteConPrestamo($test): NominaLote
{
    $lote = $test->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $test->rh);
    $recibo = $lote->recibos()->where('colaborador_id', $test->colaborador->id)->firstOrFail();
    $test->recibos->actualizar($recibo, ['conceptos' => pdrConceptos($test->prestamo)], $test->rh);

    return $lote->refresh();
}

test('preparar un borrador con descuento de préstamo no registra movimientos ni toca el saldo', function () {
    $recibo = pdrBorrador($this->colaborador, $this->prestamo, $this->rh);

    expect($recibo->estado)->toBe(EstadoReciboNomina::Borrador)
        ->and(collect($recibo->deducciones)->firstWhere('prestamo_id', $this->prestamo->id))->not->toBeNull()
        ->and(PrestamoMovimiento::query()->count())->toBe(0)
        ->and($this->prestamo->refresh()->saldo)->toBe('5000.00');
});

test('emitir registra un solo movimiento ligado al recibo y reintentar no descuenta dos veces', function () {
    $recibo = pdrBorrador($this->colaborador, $this->prestamo, $this->rh);

    $this->recibos->emitir($recibo, $this->rh);

    $movimiento = PrestamoMovimiento::query()->sole();
    expect($recibo->refresh()->estado)->toBe(EstadoReciboNomina::Emitido)
        ->and($movimiento->tipo)->toBe('nomina')
        ->and($movimiento->recibo_nomina_id)->toBe($recibo->id)
        ->and($movimiento->monto)->toBe('500.00')
        ->and($this->prestamo->refresh()->saldo)->toBe('4500.00');

    // Reintento de emisión (doble clic, cola que reintenta, lote reemitido).
    $this->recibos->emitir($recibo, $this->rh);
    // Respaldo del ledger: aunque alguien llame directo con el mismo recibo.
    app(PrestamoService::class)->registrarMovimiento($this->prestamo, 500, 'nomina', $this->rh, $recibo->id);

    expect(PrestamoMovimiento::query()->count())->toBe(1)
        ->and($this->prestamo->refresh()->saldo)->toBe('4500.00');
});

test('generar un recibo emitido directamente descuenta una sola vez al emitirse', function () {
    $recibo = $this->recibos->generar($this->colaborador, [
        'periodo_inicio' => '2026-09-14',
        'periodo_fin' => '2026-09-20',
        'fecha_pago' => '2026-09-21',
        'conceptos' => pdrConceptos($this->prestamo),
    ], $this->rh);

    expect($recibo->estado)->toBe(EstadoReciboNomina::Emitido)
        ->and(PrestamoMovimiento::query()->where('recibo_nomina_id', $recibo->id)->count())->toBe(1)
        ->and($this->prestamo->refresh()->saldo)->toBe('4500.00');
});

test('cancelar un borrador no genera movimientos y el saldo queda intacto', function () {
    $recibo = pdrBorrador($this->colaborador, $this->prestamo, $this->rh);

    $this->recibos->cancelar($recibo, $this->rh);

    expect($recibo->refresh()->estado)->toBe(EstadoReciboNomina::Cancelado)
        ->and(PrestamoMovimiento::query()->count())->toBe(0)
        ->and($this->prestamo->refresh()->saldo)->toBe('5000.00');
});

test('cancelar un lote en borrador con descuento de préstamo deja el saldo intacto', function () {
    $lote = pdrLoteConPrestamo($this);

    $this->lotes->cancelar($lote, 'Periodo equivocado', $this->rh);

    expect(PrestamoMovimiento::query()->count())->toBe(0)
        ->and($this->prestamo->refresh()->saldo)->toBe('5000.00');
});

test('recalcular un lote en borrador no toca el saldo del préstamo', function () {
    $lote = pdrLoteConPrestamo($this);

    $this->lotes->recalcular($lote, $this->rh);

    expect(PrestamoMovimiento::query()->count())->toBe(0)
        ->and($this->prestamo->refresh()->saldo)->toBe('5000.00');
});

test('editar un borrador sin reenviar la clasificación conserva la línea de préstamo', function () {
    $recibo = pdrBorrador($this->colaborador, $this->prestamo, $this->rh);

    $this->recibos->actualizar($recibo, ['conceptos' => pdrConceptos($this->prestamo, 600, clasificado: false)], $this->rh);
    expect(PrestamoMovimiento::query()->count())->toBe(0);

    $this->recibos->emitir($recibo, $this->rh);

    expect(PrestamoMovimiento::query()->sole()->monto)->toBe('600.00')
        ->and($this->prestamo->refresh()->saldo)->toBe('4400.00');
});

test('cancelar un recibo ya emitido compensa el descuento una sola vez', function () {
    $this->prestamo->update(['saldo' => 500, 'monto_original' => 5000]);
    $recibo = pdrBorrador($this->colaborador, $this->prestamo, $this->rh);
    $this->recibos->emitir($recibo, $this->rh);
    expect($this->prestamo->refresh()->estado)->toBe('liquidado');

    $this->recibos->cancelar($recibo, $this->rh);
    $this->recibos->revertirDescuentosPrestamo($recibo, $this->rh);

    $reverso = PrestamoMovimiento::query()->where('tipo', 'reverso_nomina')->sole();
    expect($reverso->recibo_nomina_id)->toBe($recibo->id)
        ->and($reverso->monto)->toBe('500.00')
        ->and(PrestamoMovimiento::query()->count())->toBe(2)
        ->and($this->prestamo->refresh()->saldo)->toBe('500.00')
        ->and($this->prestamo->estado)->toBe('activo');
});

test('cancelar un lote ya emitido regresa el saldo del préstamo', function () {
    $lote = pdrLoteConPrestamo($this);
    $this->lotes->emitir($lote, $this->rh);
    expect($this->prestamo->refresh()->saldo)->toBe('4500.00');

    $this->lotes->cancelar($lote, 'Se emitió con datos incorrectos', $this->rh);

    expect(PrestamoMovimiento::query()->where('tipo', 'nomina')->count())->toBe(1)
        ->and(PrestamoMovimiento::query()->where('tipo', 'reverso_nomina')->count())->toBe(1)
        ->and($this->prestamo->refresh()->saldo)->toBe('5000.00');
});

test('un recibo emitido no permite cambiar el descuento del préstamo que ya aplicó', function () {
    $recibo = pdrBorrador($this->colaborador, $this->prestamo, $this->rh);
    $this->recibos->emitir($recibo, $this->rh);

    expect(fn () => $this->recibos->actualizar($recibo->refresh(), ['conceptos' => pdrConceptos($this->prestamo, 800)], $this->rh))
        ->toThrow(ValidationException::class);
    expect($this->prestamo->refresh()->saldo)->toBe('4500.00')
        ->and(collect($recibo->refresh()->deducciones)->firstWhere('prestamo_id', $this->prestamo->id)['monto'])->toEqual(500);
});
