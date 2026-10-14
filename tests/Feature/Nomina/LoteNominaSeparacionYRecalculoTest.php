<?php

use App\Enums\EstadoUsuario;
use App\Enums\PeriodicidadNomina;
use App\Models\Colaborador;
use App\Models\ReciboNomina;
use App\Models\Sucursal;
use App\Services\Nomina\LoteNominaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->rh = clUsuario('rh_admin');
    // Inactivo para que no aparezca en los lotes de esta prueba (sin
    // sucursal, generaría su propio error de "sin clasificación").
    $this->rh->colaborador?->update(['sueldo_mensual' => null, 'estatus' => EstadoUsuario::Inactivo]);
    $this->lotes = app(LoteNominaService::class);

    $estructura = clEstructura();
    $this->sucursal = $estructura['sucursal'];
    $this->corporativo = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Corporativo', 'es_corporativo' => true]);
});

test('un lote semanal nunca incluye a un colaborador corporativo', function () {
    $colaboradorSucursal = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);
    $colaboradorCorporativo = Colaborador::factory()->create(['sucursal_principal_id' => $this->corporativo->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 15000, 'fecha_ingreso' => '2025-01-01']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    expect(ReciboNomina::query()->where('nomina_lote_id', $lote->id)->pluck('colaborador_id')->all())
        ->toBe([$colaboradorSucursal->id]);
    expect(ReciboNomina::query()->where('colaborador_id', $colaboradorCorporativo->id)->exists())->toBeFalse();
});

test('un lote quincenal nunca incluye a un colaborador de sucursal (no corporativo)', function () {
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);
    $corporativo = Colaborador::factory()->create(['sucursal_principal_id' => $this->corporativo->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 15000, 'fecha_ingreso' => '2025-01-01']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Quincenal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    expect(ReciboNomina::query()->where('nomina_lote_id', $lote->id)->pluck('colaborador_id')->all())
        ->toBe([$corporativo->id]);
});

test('un colaborador activo sin sucursal queda como error de clasificacion, nunca adivinado', function () {
    Colaborador::factory()->create(['sucursal_principal_id' => null, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    expect($lote->preparados)->toBe(0)
        ->and(collect($lote->errores)->pluck('motivo')->implode(' '))->toContain('clasificación');
});

test('usa salario_diario como fuente canonica: no exige sueldo_mensual si el diario ya esta capturado', function () {
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => null, 'salario_diario' => 500, 'fecha_ingreso' => '2025-01-01']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    expect($lote->preparados)->toBe(1)
        ->and($lote->errores)->toBe([])
        // 500 x 7 días.
        ->and($lote->total_neto)->toBe('3500.00');
});

test('la deteccion de duplicado considera la periodicidad: una semana no choca con una quincena distinta', function () {
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);
    Colaborador::factory()->create(['sucursal_principal_id' => $this->corporativo->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 15000, 'fecha_ingreso' => '2025-01-01']);

    $semanal = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    $quincenal = $this->lotes->preparar(PeriodicidadNomina::Quincenal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    expect($semanal->preparados)->toBe(1)
        ->and($quincenal->preparados)->toBe(1);
});

test('recalcular actualiza el salario de los borradores con los datos laborales vigentes', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => null, 'fecha_ingreso' => '2025-01-01']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    expect($lote->preparados)->toBe(0)
        ->and(collect($lote->errores)->pluck('motivo')->implode(' '))->toContain('salario');

    $colaborador->update(['salario_diario' => 400]);
    $lote = $this->lotes->recalcular($lote, $this->rh);

    expect($lote->preparados)->toBe(1)
        ->and($lote->errores)->toBe([])
        ->and($lote->total_neto)->toBe('2800.00');
});

test('recalcular rechaza un lote con cualquier recibo emitido', function () {
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    $this->lotes->emitir($lote, $this->rh);

    expect(fn () => $this->lotes->recalcular($lote, $this->rh))->toThrow(ValidationException::class);
});

test('recalcular rechaza un lote cancelado', function () {
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    $this->lotes->cancelar($lote, 'Periodo equivocado', $this->rh);

    expect(fn () => $this->lotes->recalcular($lote, $this->rh))->toThrow(ValidationException::class);
});

test('el grupo semanal se puede acotar a sucursales especificas sin tocar las demas', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => clEstructura()['empresa']->id, 'nombre' => 'Otra sucursal']);
    $enSucursal = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);
    Colaborador::factory()->create(['sucursal_principal_id' => $otra->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh, [$this->sucursal->id]);

    expect(ReciboNomina::query()->where('nomina_lote_id', $lote->id)->pluck('colaborador_id')->all())
        ->toBe([$enSucursal->id]);
});
