<?php

use App\Enums\EstadoLoteNomina;
use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoUsuario;
use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Enums\PeriodicidadNomina;
use App\Jobs\SendExpoPushJob;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\NominaLote;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\Nomina\LoteNominaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    Bus::fake([SendExpoPushJob::class]);
    $this->rh = clUsuario('rh_admin');
    $this->rh->colaborador?->update(['sueldo_mensual' => null]);
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01', 'rfc' => 'XAXX010101000', 'curp' => 'XEXX010101HNEXXXA4', 'nss' => '12345678901']);
    $this->sinSueldo = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => null]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');
    MobileDevice::factory()->for($this->cuenta, 'usuario')->create(['push_token' => 'ExponentPushToken[recibo]']);
    $this->lotes = app(LoteNominaService::class);
});

test('semanal va de lunes a domingo', function () {
    $p = $this->lotes->periodo(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'));

    expect($p['inicio']->toDateString())->toBe('2026-10-05')
        ->and($p['inicio']->isMonday())->toBeTrue()
        ->and($p['fin']->toDateString())->toBe('2026-10-11')
        ->and($p['fin']->isSunday())->toBeTrue()
        ->and($p['etiqueta'])->toBe('Semanal 5–11 octubre 2026');
});

test('quincenal: 1 al 15 y 16 al último día REAL del mes', function () {
    $q = fn (string $f) => $this->lotes->periodo(PeriodicidadNomina::Quincenal, CarbonImmutable::parse($f));

    expect($q('2026-01-10')['inicio']->toDateString())->toBe('2026-01-01')
        ->and($q('2026-01-10')['fin']->toDateString())->toBe('2026-01-15')
        ->and($q('2026-01-20')['inicio']->toDateString())->toBe('2026-01-16')
        ->and($q('2026-01-20')['fin']->toDateString())->toBe('2026-01-31')
        ->and($q('2026-04-20')['fin']->toDateString())->toBe('2026-04-30')
        ->and($q('2026-02-20')['fin']->toDateString())->toBe('2026-02-28');
});

test('quincenal en año bisiesto termina el 29 de febrero', function () {
    expect($this->lotes->periodo(PeriodicidadNomina::Quincenal, CarbonImmutable::parse('2028-02-16'))['fin']->toDateString())->toBe('2028-02-29');
});

test('preparar NO publica: borradores invisibles, sin push ni notificación, con errores y totales', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    expect($lote->estado)->toBe(EstadoLoteNomina::Preparado)
        // El colaborador de RH (sin sueldo en esta prueba) también es activo.
        ->and($lote->esperados)->toBe(3)
        ->and($lote->preparados)->toBe(1)
        ->and($lote->errores)->toHaveCount(2)
        ->and($lote->errores[0]['motivo'])->toContain('sueldo')
        // 9000 / 30 × 7 días.
        ->and($lote->total_neto)->toBe('2100.00');

    $recibo = ReciboNomina::query()->where('colaborador_id', $this->colaborador->id)->firstOrFail();
    expect($recibo->estado)->toBe(EstadoReciboNomina::Borrador)
        ->and($recibo->emitido_at)->toBeNull()
        ->and($recibo->pdf_path)->toBeNull()
        ->and($recibo->tipo_periodo)->toBe('semanal')
        ->and($recibo->nomina_lote_id)->toBe($lote->id);

    Bus::assertNotDispatched(SendExpoPushJob::class);
    Notification::assertNothingSent();

    Sanctum::actingAs($this->cuenta);
    expect($this->getJson('/api/v1/colaborador/recibos')->json('data'))->toHaveCount(0);
    $this->getJson("/api/v1/colaborador/recibos/{$recibo->id}")->assertForbidden();
});

test('la vista previa del lote genera el PDF real sin emitir ni avisar', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    $recibo = $lote->recibos()->firstOrFail();

    $this->actingAs($this->rh)
        ->get(route('rh.nomina.lotes.recibos.pdf', [$lote, $recibo]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($recibo->refresh()->estado)->toBe(EstadoReciboNomina::Borrador)
        ->and($recibo->pdf_path)->toBeNull();
    Bus::assertNotDispatched(SendExpoPushJob::class);
    Notification::assertNothingSent();
});

test('emitir publica, registra quién y cuándo, y avisa con notificación y push', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    $this->actingAs($this->rh)->post(route('rh.nomina.lotes.emitir', $lote))->assertRedirect()->assertSessionHasNoErrors();

    $lote->refresh();
    $recibo = $lote->recibos()->firstOrFail();
    expect($lote->estado)->toBe(EstadoLoteNomina::Emitido)
        ->and($lote->emitido_por)->toBe($this->rh->id)
        ->and($lote->emitido_at)->not->toBeNull()
        ->and($recibo->estado)->toBe(EstadoReciboNomina::Emitido)
        ->and($recibo->emitido_por)->toBe($this->rh->id)
        ->and($recibo->pdf_path)->not->toBeNull();

    Notification::assertSentTo($this->cuenta, PendienteRhNotification::class);
    Bus::assertDispatched(SendExpoPushJob::class, fn (SendExpoPushJob $job) => $job->token === 'ExponentPushToken[recibo]' && $job->data['type'] === 'recibo_nomina' && $job->data['resource_id'] === $recibo->id);

    Sanctum::actingAs($this->cuenta);
    $this->getJson('/api/v1/colaborador/recibos')->assertOk()->assertJsonCount(1, 'data');
});

test('un lote no se emite dos veces ni duplica avisos', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    $this->lotes->emitir($lote, $this->rh);

    expect(fn () => $this->lotes->emitir($lote, $this->rh))->toThrow(ValidationException::class);
    Bus::assertDispatchedTimes(SendExpoPushJob::class, 1);
});

test('no se crea un segundo lote (ni recibos duplicados) del mismo periodo', function () {
    $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);

    expect(fn () => $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-09'), $this->rh))
        ->toThrow(ValidationException::class);
    expect(ReciboNomina::query()->count())->toBe(1);
});

test('cancelar un lote lo conserva con motivo y libera el periodo', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    $this->lotes->cancelar($lote, 'Se capturó mal el bono', $this->rh);

    expect($lote->refresh()->estado)->toBe(EstadoLoteNomina::Cancelado)
        ->and($lote->motivo_cancelacion)->toBe('Se capturó mal el bono')
        ->and($lote->recibos()->firstOrFail()->estado)->toBe(EstadoReciboNomina::Cancelado);

    $nuevo = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    expect($nuevo->preparados)->toBe(1)
        ->and(NominaLote::query()->count())->toBe(2);
});

test('importar crea un lote de borradores y reporta las filas con error', function () {
    $csv = "numero_empleado,tipo,concepto,importe,clave\n"
        ."{$this->colaborador->numero_empleado},percepcion,Sueldo,2100,001\n"
        ."{$this->colaborador->numero_empleado},deduccion,ISR retenido,120,101\n"
        ."NO-EXISTE,percepcion,Sueldo,100,001\n";
    $archivo = UploadedFile::fake()->createWithContent('lote.csv', $csv);

    $this->actingAs($this->rh)->post(route('rh.nomina.lotes.store'), [
        'periodicidad' => 'semanal', 'fecha' => '2026-10-08', 'origen' => 'importacion', 'archivo' => $archivo,
    ])->assertRedirect();

    $lote = NominaLote::query()->firstOrFail();
    $recibo = ReciboNomina::query()->where('colaborador_id', $this->colaborador->id)->firstOrFail();

    expect($lote->origen)->toBe('importacion')
        ->and($lote->preparados)->toBe(1)
        ->and($lote->errores)->toHaveCount(1)
        ->and($recibo->estado)->toBe(EstadoReciboNomina::Borrador)
        ->and($recibo->neto)->toBe('1980.00')
        ->and($recibo->conceptos()->pluck('clave')->all())->toBe(['001', '101']);
    Bus::assertNotDispatched(SendExpoPushJob::class);
    Notification::assertNothingSent();
});

test('el recibo usa el formato oficial: encabezado patronal, caja de nómina, datos del trabajador y claves', function () {
    $this->colaborador->sucursalPrincipal->empresa->update(['razon_social' => 'PRODUCTOS Y SERVICIOS MR LANA SOCIEDAD ANONIMA PROMOTORA DE INVERSION DE CAPITAL VARIABLE', 'rfc' => 'PSM2311289J2', 'registro_patronal' => 'D1564784109', 'codigo_postal_fiscal' => '62260']);
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
    $recibo = $lote->recibos()->firstOrFail();
    $familia = FamiliaAdministrativa::ReciboNomina;

    $html = app(DocumentoAdministrativoService::class)->html($familia, app(DatosDocumentoAdministrativo::class)->recibo($recibo), app(DisenoAdministrativoService::class)->porDefecto($familia), MotorPdf::DomPdf);

    expect($html)->toContain('RECIBO DE NÓMINA')
        ->toContain('Comprobante de pago de salarios y prestaciones')
        ->toContain('PRODUCTOS Y SERVICIOS MR LANA SOCIEDAD ANONIMA')
        ->toContain('PSM2311289J2')
        ->toContain('D1564784109')
        ->toContain('62260')
        ->toContain('No. de nómina')
        ->toContain('Semanal')
        ->toContain('05-10-2026')
        ->toContain('11-10-2026')
        ->toContain('Datos del trabajador')
        ->toContain('XEXX010101HNEXXXA4')
        ->toContain('12345678901')
        ->toContain('Días del periodo')
        ->toContain('001')
        ->toContain('Firma del trabajador')
        ->not->toContain('MR. LANA PEOPLE · Documento');
});
