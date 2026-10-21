<?php

use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\HistorialSalario;
use App\Services\Compensacion\CompensacionService;
use App\Services\Finiquitos\FiniquitoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

/*
| «Datos laborales» del expediente: el sueldo que RH captura es el que queda
| (y el que se ve). Regresión: 11,100.10 → 11,100.00 decía «éxito» pero el
| valor viejo seguía, porque el cambio se detectaba sobre el salario DIARIO
| redondeado (370.00 en ambos casos) y no sobre el mensual guardado.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
});

function sdColaborador(?float $mensual, ?float $diario): Colaborador
{
    $estructura = clEstructura();

    return Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $estructura['puesto']->id,
        'sueldo_mensual' => $mensual,
        'salario_diario' => $diario,
    ]);
}

test('11,100.10 → 11,100.00: se guarda y se ve el nuevo sueldo (rh_admin y super_admin)', function (string $rol) {
    // Dato heredado: mensual 11,100.10 con diario 370.00 (11,100.10 ÷ 30 redondeado).
    $colaborador = sdColaborador(11100.10, 370.00);
    $this->actingAs(clUsuario($rol));

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['sueldo_mensual' => '11100.00', 'motivo' => 'Corrección de centavos mal capturados.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $t): bool => $t['type'] === 'success');

    $colaborador->refresh();
    expect((float) $colaborador->sueldo_mensual)->toBe(11100.0)
        ->and((float) $colaborador->salario_diario)->toBe(370.0);
})->with(['rh_admin', 'super_admin']);

test('5,000 → 10,000: queda exactamente 10,000.00 (no 9,999.90), en el historial salarial y en el contrato vigente', function () {
    $colaborador = sdColaborador(5000, round(5000 / 30, 2));
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'estado' => 'vigente', 'sueldo_mensual' => 5000]);
    $this->actingAs(clUsuario('rh_admin'));

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['sueldo_mensual' => '10000', 'motivo' => 'Ajuste de sueldo autorizado por Dirección.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $t): bool => $t['type'] === 'success');

    expect((float) $colaborador->refresh()->sueldo_mensual)->toBe(10000.0)
        ->and((float) $colaborador->salario_diario)->toBe(333.33)
        ->and((float) $contrato->refresh()->sueldo_mensual)->toBe(10000.0)
        ->and(app(CompensacionService::class)->mensualDe($colaborador))->toBe(10000.0)
        ->and(app(FiniquitoService::class)->sueldoMensualSugerido($colaborador))->toBe(10000.0)
        ->and(HistorialSalario::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);
});

test('cambiar el sueldo SIN motivo: se rechaza en «motivo» (dato crítico), no se guarda y no dice éxito', function () {
    $colaborador = sdColaborador(9000, 300);
    $this->actingAs(clUsuario('rh_admin'));

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['sueldo_mensual' => '9500'])
        ->assertSessionHasErrors(['motivo' => 'Escribe el motivo del cambio de sueldo (dato crítico).'])
        ->assertSessionMissing('toast');

    // El motivo viaja con la llave «motivo» (el input del formulario es #motivo_laboral).
    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['sueldo_mensual' => '9500', 'motivo' => 'Ajuste anual de sueldo.'])
        ->assertSessionHasNoErrors();

    expect((float) $colaborador->refresh()->sueldo_mensual)->toBe(9500.0);
});

test('sin cambios: no dice «éxito», dice que no hubo cambios', function () {
    $colaborador = sdColaborador(9000, 300);
    $this->actingAs(clUsuario('rh_admin'));

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['sueldo_mensual' => '9000.00'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $t): bool => $t['type'] === 'info' && str_contains($t['message'], 'No hubo cambios'));

    expect(HistorialSalario::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);
});

test('9,582.47 se guarda EXACTO (no 9,582.60) y el diario se deriva de él: 319.42', function () {
    $colaborador = sdColaborador(9000, 300);
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'estado' => 'vigente', 'sueldo_mensual' => 9000]);
    $this->actingAs(clUsuario('super_admin'));

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['sueldo_mensual' => '9582.47', 'motivo' => 'Sueldo del tabulador con centavos.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $t): bool => $t['type'] === 'success');

    $colaborador->refresh();
    expect((float) $colaborador->sueldo_mensual)->toBe(9582.47)
        ->and((float) $colaborador->salario_diario)->toBe(319.42)
        ->and((float) $contrato->refresh()->sueldo_mensual)->toBe(9582.47)
        ->and(app(CompensacionService::class)->mensualDe($colaborador))->toBe(9582.47)
        ->and(app(FiniquitoService::class)->sueldoMensualSugerido($colaborador))->toBe(9582.47);
});

test('un mensual con centavos que no salen de diario × 30 se conserva tal cual lo capturó RH', function () {
    $colaborador = sdColaborador(11100.00, 370.00);
    $this->actingAs(clUsuario('rh_admin'));

    $this->put("/rh/expedientes/{$colaborador->id}/datos-laborales", ['sueldo_mensual' => '11100.10', 'motivo' => 'Captura con centavos del tabulador.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $t): bool => $t['type'] === 'success');

    expect((float) $colaborador->refresh()->sueldo_mensual)->toBe(11100.1)
        ->and((float) $colaborador->salario_diario)->toBe(370.0);
});
