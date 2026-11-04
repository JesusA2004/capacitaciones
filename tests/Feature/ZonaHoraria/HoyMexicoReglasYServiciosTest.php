<?php

use App\Http\Requests\Bajas\GuardarAvisoRescisionRequest;
use App\Http\Requests\CicloLaboral\EntregaFiniquitoRequest;
use App\Http\Requests\CicloLaboral\GuardarActaRequest;
use App\Http\Requests\CicloLaboral\ProgramarPagoRequest;
use App\Http\Requests\Incapacidades\RegistrarIncapacidadRequest;
use App\Http\Requests\Rh\GuardarResponsivaRequest;
use App\Models\CartaCompromiso;
use App\Models\SolicitudInterna;
use App\Support\FechaMexico;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;

/*
 * «Hoy» de negocio = día calendario de México, no de UTC (app.timezone es
 * UTC). 2026-11-04 01:30 UTC = martes 3 de noviembre, 19:30 en CDMX.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-04 01:30:00 UTC');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('FechaMexico da el 3 de noviembre aunque en UTC ya sea 4', function () {
    expect(FechaMexico::hoy())->toBe('2026-11-03')
        ->and(FechaMexico::inicioDeHoyInmutable()->toDateString())->toBe('2026-11-03');
});

test('a las 19:30 CDMX se pueden pedir vacaciones que empiezan hoy (web y app)', function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $usuario = clUsuario('gerente', ['fecha_ingreso' => '2023-06-01']);
    Sanctum::actingAs($usuario);

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'vacaciones', 'motivo' => 'Descanso', 'dias' => ['2026-11-03', '2026-11-04']])
        ->assertCreated();

    expect(SolicitudInterna::query()->where('user_id', $usuario->id)->count())->toBe(1);
});

test('las reglas «no futura» aceptan hoy CDMX y rechazan mañana CDMX', function (string $clase, string $campo) {
    $reglas = (new $clase)->rules();
    $regla = collect((array) $reglas[$campo])->first(fn ($r) => is_string($r) && str_starts_with($r, 'before_or_equal:'));

    expect($regla)->toBe('before_or_equal:2026-11-03');

    expect(Validator::make([$campo => '2026-11-03'], [$campo => ['date', $regla]])->passes())->toBeTrue()
        ->and(Validator::make([$campo => '2026-11-04'], [$campo => ['date', $regla]])->passes())->toBeFalse();
})->with([
    'aviso de rescisión' => [GuardarAvisoRescisionRequest::class, 'fecha_aviso'],
    'entrega de finiquito' => [EntregaFiniquitoRequest::class, 'fecha'],
    'acta' => [GuardarActaRequest::class, 'fecha'],
    'incapacidad ST-7' => [RegistrarIncapacidadRequest::class, 'fecha_st7'],
    'responsiva' => [GuardarResponsivaRequest::class, 'fecha_entrega'],
]);

test('programar el pago del finiquito acepta hoy CDMX (antes lo rechazaba después de las 18:00)', function () {
    $regla = collect((new ProgramarPagoRequest)->rules()['fecha'])->first(fn ($r) => is_string($r) && str_starts_with($r, 'after_or_equal:'));

    expect($regla)->toBe('after_or_equal:2026-11-03')
        ->and(Validator::make(['fecha' => '2026-11-03'], ['fecha' => ['date', $regla]])->passes())->toBeTrue();
});

test('la carta compromiso que vence hoy CDMX todavía no está vencida', function () {
    $carta = (new CartaCompromiso)->forceFill(['fecha_limite' => '2026-11-03']);

    expect($carta->plazoVencido())->toBeFalse()
        ->and(((new CartaCompromiso)->forceFill(['fecha_limite' => '2026-11-02']))->plazoVencido())->toBeTrue()
        ->and(((new CartaCompromiso)->forceFill(['fecha_limite' => '2026-11-05']))->diasRestantes())->toBe(2);
});
