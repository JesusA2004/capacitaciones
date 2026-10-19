<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Solicitudes\BajaColaboradorService;
use App\Support\FechaMexico;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Flujo ANTERIOR del finiquito apagado (features.cierre_flujo_legacy) y
| fechas legales (fecha_baja / fecha_efectiva) en horario de México: la app
| corre en UTC y de 18:00 a 24:00 de México el día calendario no debe
| adelantarse.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    config(['features.cierre_flujo_legacy' => false]);

    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
        'salario_diario' => '315.04',
        'sueldo_mensual' => '9451.20',
        'estatus' => EstadoUsuario::Activo,
        'estado_alta' => EstadoAltaColaborador::Activo->value,
    ]);
    User::factory()->create(['colaborador_id' => $this->colaborador->id])->assignRole('colaborador');

    $this->cierres = app(CierreLaboralService::class);
    $this->cierre = $this->cierres->solicitar($this->colaborador, [
        'tipo_baja' => 'renuncia',
        'motivo' => 'Renuncia voluntaria.',
        'fecha_efectiva' => now()->toDateString(),
    ], $this->rh);
});

test('con el flag apagado, los pasos del flujo anterior del finiquito se rechazan sobre un cierre nuevo', function () {
    $this->cierres->calcularFiniquito($this->cierre, $this->rh);

    // autorizar / programar pago / cita / firma / confirmar pago: ya no son
    // parte del flujo vigente — cada uno lanza ValidationException.
    expect(fn () => $this->cierres->autorizarFiniquito($this->cierre->refresh(), $this->rh))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->cierres->programarPago($this->cierre->refresh(), $this->rh, ['fecha' => now()->toDateString(), 'metodo' => 'transferencia']))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->cierres->registrarCita($this->cierre->refresh(), $this->rh, now()->toDateTimeString()))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->cierres->registrarFiniquitoFirmado($this->cierre->refresh(), clArchivoPdf('firmado.pdf'), $this->rh))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->cierres->confirmarPago($this->cierre->refresh(), $this->rh, 'SPEI-1'))
        ->toThrow(ValidationException::class);

    // El cierre no avanzó al tramo legacy: sigue en finiquito en proceso.
    expect($this->cierre->refresh()->estado)->toBe(EstadoCierreLaboral::FiniquitoEnProceso);
});

test('las rutas web del flujo anterior del finiquito responden error con el flag apagado', function () {
    $this->cierres->calcularFiniquito($this->cierre, $this->rh);

    $this->actingAs($this->rh)
        ->post("/rh/cierres/{$this->cierre->id}/pago/programar", ['fecha' => now()->toDateString(), 'metodo' => 'transferencia'])
        ->assertSessionHasErrors();
    $this->actingAs($this->rh)
        ->post("/rh/cierres/{$this->cierre->id}/pago/confirmar", ['referencia' => 'SPEI-1'])
        ->assertSessionHasErrors();

    expect($this->cierre->refresh()->estado)->toBe(EstadoCierreLaboral::FiniquitoEnProceso);
});

test('la baja iniciada por RH procede sin paso de autorización: queda aplicada y el finiquito avanza de inmediato', function () {
    // solicitar() ya corrió en beforeEach (RH): el cierre nace aplicado.
    expect($this->cierre->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and($this->cierre->autorizado_rh_en)->not->toBeNull()
        ->and($this->cierre->autorizado_rh_por)->toBe($this->rh->id);

    // Sin autorización previa: RH calcula el finiquito sin ningún gate extra.
    $finiquito = $this->cierres->calcularFiniquito($this->cierre, $this->rh);
    expect($finiquito->neto)->not->toBeNull();

    // No existe ninguna acción de autorizar / programar / cita en el flujo moderno.
    $claves = collect($this->cierres->accionesPermitidas($this->cierre->refresh(), $this->rh))->pluck('clave')->all();
    expect($claves)->not->toContain('autorizar_finiquito')
        ->not->toContain('programar_pago')
        ->not->toContain('registrar_cita')
        ->not->toContain('confirmar_pago')
        ->toContain('registrar_entrega_finiquito');
});

test('FechaMexico::hoy() usa el día calendario de México aunque en UTC ya sea el día siguiente', function () {
    // 02:00 UTC = 20:00 del día anterior en México (UTC-6, sin horario de verano).
    Carbon::setTestNow(Carbon::parse('2026-10-19 02:00:00', 'UTC'));

    expect(FechaMexico::hoy())->toBe('2026-10-18')
        ->and(FechaMexico::inicioDeHoy()->toDateString())->toBe('2026-10-18')
        ->and(now()->toDateString())->toBe('2026-10-19'); // UTC sí ya cambió de día

    Carbon::setTestNow();
});

test('la baja no se ejecuta antes de su fecha efectiva tomando el día de México (no el de UTC)', function () {
    // Noche de México: UTC ya es 19, pero en México sigue siendo 18.
    Carbon::setTestNow(Carbon::parse('2026-10-19 02:00:00', 'UTC'));

    $cierre = $this->cierre;
    $cierre->update(['fecha_efectiva' => '2026-10-19']); // mañana en México
    $this->cierres->calcularFiniquito($cierre->refresh(), $this->rh);

    // Con la fecha de México (18) la efectiva (19) aún es futura → se rechaza.
    // Con la fecha de UTC (19) el gate dejaría ejecutar un día antes (el bug).
    expect(fn () => $this->cierres->registrarEntregaFiniquito($cierre->refresh(), $this->rh, ['fecha' => '2026-10-19']))
        ->toThrow(ValidationException::class);

    Carbon::setTestNow();
});

test('BajaColaboradorService fija fecha_baja con el día de México cuando no venía una fecha', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 02:00:00', 'UTC'));

    $persona = Colaborador::factory()->create([
        'estatus' => EstadoUsuario::Activo,
        'estado_alta' => EstadoAltaColaborador::Activo->value,
        'fecha_ingreso' => '2024-01-01',
        'fecha_baja' => null,
    ]);

    app(BajaColaboradorService::class)->ejecutar($persona, $this->rh, 'Prueba zona horaria.');

    expect(Colaborador::withTrashed()->findOrFail($persona->id)->fecha_baja->toDateString())->toBe('2026-10-18');

    Carbon::setTestNow();
});
