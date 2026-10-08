<?php

use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\NominaLote;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Nomina\NominaQuincenalService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    // El usuario RH también es colaborador: sin sueldo para que la
    // quincena solo cuente a las personas de esta prueba.
    $this->rh->colaborador?->update(['sueldo_mensual' => null]);
    $estructura = clEstructura();
    $this->conSueldo = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 12000, 'fecha_ingreso' => '2025-01-01']);
    $this->nuevo = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2026-10-11']);
    $this->sinSueldo = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => null]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->conSueldo->id]);
    $this->cuenta->assignRole('colaborador');
    $this->quincenas = app(NominaQuincenalService::class);
});

test('calcula las quincenas: 1 al 15 y 16 al fin de mes, pago el último día', function () {
    $p = $this->quincenas->periodo(CarbonImmutable::parse('2026-02-20'));

    expect($p['clave'])->toBe('2026-02-2')
        ->and($p['inicio']->toDateString())->toBe('2026-02-16')
        ->and($p['fin']->toDateString())->toBe('2026-02-28')
        ->and($p['pago']->toDateString())->toBe('2026-02-28')
        ->and($p['numero'])->toBe(4)
        ->and($this->quincenas->periodoPorClave('2026-10-1')['fin']->toDateString())->toBe('2026-10-15');
});

test('prepara borradores: mitad del sueldo, proporcional al ingresar a media quincena y reporta a quien no tiene sueldo', function () {
    $periodo = $this->quincenas->periodoPorClave('2026-10-1');
    $resultado = $this->quincenas->preparar($periodo, $this->rh);

    expect($resultado['creados'])->toBe(2)
        ->and(collect($resultado['omitidos'])->pluck('colaborador_id')->all())->toContain($this->sinSueldo->id);

    $completo = ReciboNomina::query()->where('colaborador_id', $this->conSueldo->id)->firstOrFail();
    $proporcional = ReciboNomina::query()->where('colaborador_id', $this->nuevo->id)->firstOrFail();

    expect($completo->estado)->toBe(EstadoReciboNomina::Borrador)
        ->and($completo->neto)->toBe('6000.00')
        ->and($completo->pdf_path)->toBeNull()
        ->and($completo->tipo_periodo)->toBe('quincenal')
        // Ingresó el 11: 5 días × 9000/30.
        ->and($proporcional->neto)->toBe('1500.00');

    // Idempotente: correrlo otra vez no duplica.
    expect($this->quincenas->preparar($periodo, $this->rh)['creados'])->toBe(0)
        ->and(ReciboNomina::query()->count())->toBe(2);
});

test('el colaborador no ve borradores; al emitirse tiene PDF y ya lo ve', function () {
    $periodo = $this->quincenas->periodoPorClave('2026-10-1');
    $this->quincenas->preparar($periodo, $this->rh);
    $recibo = ReciboNomina::query()->where('colaborador_id', $this->conSueldo->id)->firstOrFail();

    Sanctum::actingAs($this->cuenta);
    expect($this->getJson('/api/v1/colaborador/recibos')->json('data'))->toHaveCount(0);
    $this->getJson("/api/v1/colaborador/recibos/{$recibo->id}")->assertForbidden();

    $this->quincenas->emitirPeriodo($periodo, $this->rh);

    $recibo->refresh();
    expect($recibo->estado)->toBe(EstadoReciboNomina::Emitido)
        ->and($recibo->pdf_path)->not->toBeNull();
    $this->getJson('/api/v1/colaborador/recibos')->assertOk()->assertJsonCount(1, 'data');
});

test('cambio en bloque: agrega un bono a todos sin duplicarlo y lo quita', function () {
    $periodo = $this->quincenas->periodoPorClave('2026-10-1');
    $this->quincenas->preparar($periodo, $this->rh);
    $datos = ['accion' => 'agregar', 'tipo' => 'percepcion', 'concepto' => 'Bono de puntualidad', 'importe' => 300];

    expect($this->quincenas->aplicarMasivo($periodo, $this->rh, $datos))->toBe(2);
    $this->quincenas->aplicarMasivo($periodo, $this->rh, [...$datos, 'importe' => 500]);

    $recibo = ReciboNomina::query()->where('colaborador_id', $this->conSueldo->id)->firstOrFail();
    expect($recibo->neto)->toBe('6500.00')
        ->and($recibo->conceptos()->count())->toBe(2);

    $this->quincenas->aplicarMasivo($periodo, $this->rh, ['accion' => 'quitar', 'tipo' => 'percepcion', 'concepto' => 'bono de puntualidad']);
    expect($recibo->refresh()->neto)->toBe('6000.00');
});

test('rh edita un recibo uno por uno desde la web y descarga la quincena en zip y pdf', function () {
    $periodo = $this->quincenas->periodoPorClave('2026-10-1');
    $this->quincenas->preparar($periodo, $this->rh);
    $recibo = ReciboNomina::query()->where('colaborador_id', $this->conSueldo->id)->firstOrFail();

    $this->actingAs($this->rh)->put("/rh/nomina/recibos/{$recibo->id}", ['conceptos' => [
        ['tipo' => 'percepcion', 'concepto' => 'Sueldo quincenal', 'importe' => 6000],
        ['tipo' => 'deduccion', 'concepto' => 'Préstamo', 'importe' => 1000],
    ]])->assertRedirect();

    expect($recibo->refresh()->neto)->toBe('5000.00');

    $this->actingAs($this->rh)->post('/rh/nomina/emitir', ['periodo' => '2026-10-1'])->assertRedirect();

    $this->actingAs($this->rh)->get('/rh/nomina?periodo=2026-10-1')->assertOk();
    $this->actingAs($this->rh)->get('/rh/nomina/descargar?periodo=2026-10-1&formato=zip')
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
    $this->actingAs($this->rh)->get('/rh/nomina/descargar?periodo=2026-10-1&formato=pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('el comando prepara el lote antes del pago y NUNCA publica por su cuenta', function () {
    $this->artisan('nomina:procesar-quincenas', ['--fecha' => '2026-10-05'])->assertSuccessful();
    expect(ReciboNomina::query()->count())->toBe(0);

    $this->artisan('nomina:procesar-quincenas', ['--fecha' => '2026-10-13'])->assertSuccessful();
    expect(ReciboNomina::query()->where('estado', 'borrador')->count())->toBe(2)
        ->and(NominaLote::query()->where('estado', 'preparado')->count())->toBe(1);

    // Llega la fecha de pago: sigue sin publicarse (RH revisa y emite) y no se crea otro lote.
    $this->artisan('nomina:procesar-quincenas', ['--fecha' => '2026-10-15'])->assertSuccessful();
    expect(ReciboNomina::query()->where('estado', 'emitido')->count())->toBe(0)
        ->and(NominaLote::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('con NOMINA_EMISION_AUTOMATICA=true el comando emite en la fecha de pago', function () {
    config(['nomina.quincenal.emision_automatica' => true]);

    $this->artisan('nomina:procesar-quincenas', ['--fecha' => '2026-10-13'])->assertSuccessful();
    $this->artisan('nomina:procesar-quincenas', ['--fecha' => '2026-10-15'])->assertSuccessful();

    expect(ReciboNomina::query()->where('estado', 'emitido')->count())->toBe(2);
});

test('un colaborador sin permiso no entra al centro de recibos', function () {
    $this->actingAs($this->cuenta)->get('/rh/nomina')->assertForbidden();
    $this->actingAs($this->cuenta)->post('/rh/nomina/preparar', ['periodo' => '2026-10-1'])->assertForbidden();
});
